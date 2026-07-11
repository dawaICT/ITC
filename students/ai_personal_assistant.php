<?php
declare(strict_types=1);

$page_title = 'AI Assistant';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';
require_once dirname(__DIR__) . '/includes/chatbot_db.php';
require_once __DIR__ . '/includes/ai_student_context.php';

function ai_pa_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$studentId = (string)($_SESSION['Sid'] ?? '');
$aiStatus  = wuc_ai_local_status();
$ctx       = wuc_ai_student_context($db, $studentId);

// Load previous conversation from DB for server-side rendering
if (empty($_SESSION['chatbot_schema_checked'])) {
    wuc_chatbot_ensure_schema($db);
    $_SESSION['chatbot_schema_checked'] = true;
}
$_paConvoSessionKey = 'chatbot_convo_student_' . md5($studentId);
$_paConvoId = isset($_SESSION[$_paConvoSessionKey]) ? (int)$_SESSION[$_paConvoSessionKey] : null;
if ($_paConvoId === null) {
    $_paConvoId = wuc_chatbot_get_or_create_convo($db, $studentId, 'student', 'student');
    if ($_paConvoId !== null) {
        $_SESSION[$_paConvoSessionKey] = $_paConvoId;
    }
}
$_paHistory = $_paConvoId !== null ? wuc_chatbot_load_history($db, $_paConvoId, 12) : [];

$firstName = trim((string)($ctx['name'] ?? ''));
$firstName = $firstName !== '' ? explode(' ', $firstName)[0] : 'there';
$feesBalance = (float)($ctx['fees']['balance_due'] ?? 0);
$feesPaid    = (float)($ctx['fees']['total_paid'] ?? 0);
$feesBilled  = (float)($ctx['fees']['total_invoiced'] ?? 0);
$courseCount = count($ctx['registered_courses'] ?? []);
$semReg      = !empty($ctx['semester_registration_id']) || !empty($ctx['current_period']);
$periodLabel   = (string)($ctx['period_label'] ?? (strtolower((string)($ctx['period_mode'] ?? '')) === 'term' ? 'Term' : 'Semester'));
$periodType    = strtolower($periodLabel);
$currentPeriod = !empty($ctx['current_period'])
    ? ($periodLabel === 'Module' ? 'Enrolled' : $periodLabel . ' ' . $ctx['current_period'])
    : 'Pending';
$periodYear = trim((string)($ctx['semester_academic_year'] ?? $ctx['academic_year'] ?? ''));
if ($periodYear !== '' && $currentPeriod !== 'Pending') {
    $currentPeriod .= ' / ' . $periodYear;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title . ' - ITC', ENT_QUOTES, 'UTF-8'); ?></title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<?php
require_once __DIR__ . '/includes/navbar.php';
echo wuc_ai_output_styles();

// Safe student avatar for chat bubbles (navbar has cached the profile image
// in the session by this point; falls back to the default avatar).
require_once __DIR__ . '/includes/ai_scope_guard.php';
$paStudentAvatar = wuc_ai_student_avatar_url();
?>

<style>
.ai-chat-shell {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 230px);
    min-height: 460px;
}
.ai-chat-thread {
    flex: 1;
    overflow-y: auto;
    padding: 1.25rem;
    background: rgba(250, 249, 253, 0.4);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1px solid rgba(111, 66, 193, 0.16);
    border-radius: 16px;
    box-shadow: inset 0 2px 10px rgba(111, 66, 193, 0.03);
}
.ai-chat-thread::-webkit-scrollbar {
    width: 6px;
}
.ai-chat-thread::-webkit-scrollbar-track {
    background: transparent;
}
.ai-chat-thread::-webkit-scrollbar-thumb {
    background: rgba(111, 66, 193, 0.2);
    border-radius: 4px;
}
.ai-chat-thread::-webkit-scrollbar-thumb:hover {
    background: rgba(111, 66, 193, 0.4);
}
.ai-msg {
    display: flex;
    gap: 12px;
    margin-bottom: 1.15rem;
    align-items: flex-start;
}
.ai-msg .avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 14px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}
.ai-msg.assistant .avatar {
    background: #6f42c1;
}
.ai-msg.user {
    flex-direction: row-reverse;
}
.ai-msg.user .avatar {
    background: #495057;
}
.ai-msg .avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}
.ai-bubble {
    max-width: 78%;
    padding: 0.75rem 1.1rem;
    border-radius: 16px;
    font-size: 0.95rem;
    line-height: 1.6;
    box-shadow: 0 2px 12px rgba(111, 66, 193, 0.04);
}
.ai-msg.assistant .ai-bubble {
    background: #fff;
    border: 1px solid rgba(230, 225, 240, 0.8);
    border-top-left-radius: 4px;
    color: #212529;
}
.ai-msg.user .ai-bubble {
    background: #6f42c1;
    color: #fff;
    border-top-right-radius: 4px;
    box-shadow: 0 4px 14px rgba(111, 66, 193, 0.16);
}
.ai-msg.user .ai-bubble * {
    color: #fff !important;
}
.ai-chat-input {
    display: flex;
    gap: 10px;
    margin-top: 0.9rem;
}
.ai-chat-input textarea {
    resize: none;
    border-radius: 12px;
    border: 1px solid #d7cdee;
    padding: 10px 14px;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.ai-chat-input textarea:focus {
    outline: none;
    border-color: #6f42c1;
    box-shadow: 0 0 0 3px rgba(111, 66, 193, 0.15);
}
.ai-chat-input button {
    border-radius: 12px;
    width: 52px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #6f42c1;
    border: none;
    transition: background 0.15s, transform 0.15s;
}
.ai-chat-input button:hover:not(:disabled) {
    background: #5a32a3;
    transform: scale(1.03);
}
.ai-chip {
    cursor: pointer;
    border: 1px solid #d7cdee;
    background: #fff;
    color: #5a32a3;
    border-radius: 20px;
    padding: 0.4rem 0.95rem;
    font-size: 0.83rem;
    transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 6px rgba(111, 66, 193, 0.04);
}
.ai-chip:hover {
    background: #6f42c1;
    color: #fff;
    border-color: #6f42c1;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(111, 66, 193, 0.15);
}
.ai-typing span {
    display: inline-block;
    width: 7px;
    height: 7px;
    margin: 0 2px;
    background: #6f42c1;
    border-radius: 50%;
    animation: aiblink 1.2s infinite both;
}
.ai-typing span:nth-child(2) {
    animation-delay: 0.2s;
}
.ai-typing span:nth-child(3) {
    animation-delay: 0.4s;
}
@keyframes aiblink {
    0%, 80%, 100% { opacity: 0.2; transform: scale(0.8); }
    40% { opacity: 1; transform: scale(1.2); }
}
.ai-output {
    font-size: 0.95rem;
}
</style>

<main class="content-wrapper pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4 portal-dashboard ai-personal-assistant-page">

    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title"><img src="/wucportal/assets/images/chatbot/avatar.svg" alt="" style="width:30px;height:30px;border-radius:50%;vertical-align:middle;margin-right:8px;">AI Assistant</h1>
                <p class="text-muted mb-0">Chat about your registration, courses, fees, and results — it remembers the conversation.</p>
            </div>
            <div class="col-auto d-flex align-items-center gap-2">
                <span class="badge <?php echo $aiStatus['model_ready'] ? 'bg-success' : 'bg-secondary'; ?> fs-6">
                    <i class="fas fa-circle-dot me-1"></i><?php echo $aiStatus['model_ready'] ? 'AI online' : 'Fallback mode'; ?>
                </span>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="aiNewChat">
                    <i class="fas fa-rotate-left me-1"></i>New chat
                </button>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="ai-chat-shell">
                <div class="ai-chat-thread" id="aiThread">
                    <div class="ai-msg assistant" id="aiWelcomeMsg">
                        <div class="avatar"><img src="/wucportal/assets/images/chatbot/avatar.svg" alt="ITC Assistant"></div>
                        <div class="ai-bubble">
                            <div class="ai-output">
                                <?php if ($_paHistory): ?>
                                <p>Welcome back<?php echo $firstName !== 'there' ? ', ' . ai_pa_h($firstName) : ''; ?>. I can see your previous conversation below. Feel free to continue or ask something new.</p>
                                <?php else: ?>
                                <p><?php echo $firstName !== 'there' ? 'Hello, ' . ai_pa_h($firstName) . '.' : 'Hello.'; ?> I am your ITC academic assistant. I can see your academic record and am here to help with questions about your registration, courses, fees, and results.</p>
                                <?php endif; ?>
                            </div>
                            <?php if (!$_paHistory): ?>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <span class="ai-chip">What is my fee balance?</span>
                                <span class="ai-chip">Which courses am I registered for?</span>
                                <span class="ai-chip">Am I registered this <?= htmlspecialchars(strtolower($periodLabel)) ?>?</span>
                                <span class="ai-chip">Summarise my assessment marks</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php foreach ($_paHistory as $_paMsg): ?>
                    <?php $isStu = $_paMsg['role'] === 'user'; ?>
                    <div class="ai-msg <?php echo $isStu ? 'user' : 'assistant'; ?>">
                        <div class="avatar">
                            <?php if ($isStu): ?><img src="<?php echo ai_pa_h($paStudentAvatar); ?>" alt="You"><?php else: ?><img src="/wucportal/assets/images/chatbot/avatar.svg" alt="ITC Assistant"><?php endif; ?>
                        </div>
                        <div class="ai-bubble">
                            <?php if ($isStu): ?>
                                <?php echo ai_pa_h($_paMsg['content']); ?>
                            <?php else: ?>
                                <div class="ai-output"><?php echo wuc_ai_render_markdown($_paMsg['content']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="ai-chat-input">
                    <textarea class="form-control" id="aiInput" rows="2" maxlength="800"
                        placeholder="Ask anything about your studies…"></textarea>
                    <button class="btn btn-primary px-3" id="aiSend" type="button" title="Send">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
                <div class="form-text mt-1">Press Enter to send · Shift+Enter for a new line · 40 messages/hour</div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="data-table-card mb-3">
                <div class="card-header"><h6 class="mb-0"><i class="fas fa-gauge-high me-2"></i>Your snapshot</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between py-1"><span class="text-muted">Registered courses</span><strong><?php echo $courseCount; ?></strong></div>
                    <div class="d-flex justify-content-between py-1"><span class="text-muted">Current <?php echo strtolower(ai_pa_h($periodLabel)); ?></span><strong class="<?php echo $semReg ? 'text-success' : 'text-warning'; ?>"><?php echo ai_pa_h($currentPeriod); ?></strong></div>
                    <div class="d-flex justify-content-between py-1"><span class="text-muted">Total billed</span><strong>ZMW <?php echo number_format($feesBilled, 0); ?></strong></div>
                    <div class="d-flex justify-content-between py-1"><span class="text-muted">Total paid</span><strong class="text-success">ZMW <?php echo number_format($feesPaid, 0); ?></strong></div>
                    <div class="d-flex justify-content-between py-1"><span class="text-muted">Balance due</span><strong class="<?php echo $feesBalance > 0 ? 'text-danger' : 'text-success'; ?>">ZMW <?php echo number_format($feesBalance, 0); ?></strong></div>
                    <div class="d-flex justify-content-between py-1"><span class="text-muted"><?php echo ai_pa_h($periodLabel); ?> status</span><strong class="<?php echo $semReg ? 'text-success' : 'text-warning'; ?>"><?php echo $semReg ? 'Active' : 'Pending'; ?></strong></div>
                </div>
            </div>
            <div class="data-table-card">
                <div class="card-header"><h6 class="mb-0"><i class="fas fa-lightbulb me-2"></i>Tips</h6></div>
                <div class="card-body small text-muted">
                    <p class="mb-2">This assistant remembers your conversation, so you can ask follow-ups like “and what about last semester?”.</p>
                    <p class="mb-0">It only sees <em>your</em> records and never shares them. For official statements, always confirm with the relevant office.</p>
                </div>
            </div>
        </div>
    </div>

</div>
</main>

<script>
(function(){
    'use strict';
    var csrf = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
    var thread = document.getElementById('aiThread');
    var input = document.getElementById('aiInput');
    var sendBtn = document.getElementById('aiSend');
    var newChatBtn = document.getElementById('aiNewChat');
    var busy = false;

    function esc(s){ return (s||'').replace(/[&<>"]/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

    function scrollDown(){ thread.scrollTop = thread.scrollHeight; }

    var stuAvImg = '<img src="' + esc(<?php echo json_encode($paStudentAvatar); ?>) + '" alt="You">';
    function addUser(text){
        var el = document.createElement('div');
        el.className = 'ai-msg user';
        el.innerHTML = '<div class="avatar">' + stuAvImg + '</div><div class="ai-bubble">' + esc(text) + '</div>';
        thread.appendChild(el); scrollDown();
    }

    var avImg = '<img src="/wucportal/assets/images/chatbot/avatar.svg" alt="ITC Assistant">';
    function addAssistantHtml(html){
        var el = document.createElement('div');
        el.className = 'ai-msg assistant';
        el.innerHTML = '<div class="avatar">' + avImg + '</div><div class="ai-bubble">' + html + '</div>';
        thread.appendChild(el); scrollDown();
    }

    function addTyping(){
        var el = document.createElement('div');
        el.className = 'ai-msg assistant'; el.id = 'aiTyping';
        el.innerHTML = '<div class="avatar">' + avImg + '</div><div class="ai-bubble"><div class="ai-typing"><span></span><span></span><span></span></div></div>';
        thread.appendChild(el); scrollDown();
    }
    function removeTyping(){ var t = document.getElementById('aiTyping'); if (t) t.remove(); }

    async function send(text){
        if (busy) return;
        text = (text || input.value).trim();
        if (!text) return;
        busy = true; sendBtn.disabled = true;
        addUser(text);
        input.value = '';
        addTyping();
        try {
            var resp = await fetch('ai_chat_ajax.php', {
                method:'POST',
                headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
                body: JSON.stringify({ message: text, csrf_token: csrf, page: window.location.pathname })
            });
            var data = await resp.json();
            removeTyping();
            if (!resp.ok || !data.ok) {
                addAssistantHtml('<div class="ai-output"><p class="text-danger mb-0">' + esc(data.message || ('Error '+resp.status)) + '</p></div>');
            } else {
                addAssistantHtml(data.reply_html);
                if (!data.used_ai) {
                    var n = thread.lastChild.querySelector('.ai-bubble');
                    if (n) n.insertAdjacentHTML('beforeend', '<div class="small text-warning mt-1"><i class="fas fa-triangle-exclamation me-1"></i>Offline fallback</div>');
                }
            }
        } catch(e){
            removeTyping();
            addAssistantHtml('<div class="ai-output"><p class="text-danger mb-0">Could not reach the assistant. Please try again.</p></div>');
        } finally {
            busy = false; sendBtn.disabled = false; input.focus();
        }
    }

    sendBtn.addEventListener('click', function(){ send(); });
    input.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); send(); }
    });
    document.addEventListener('click', function(e){
        if (e.target.classList && e.target.classList.contains('ai-chip')) { send(e.target.textContent); }
    });
    newChatBtn.addEventListener('click', async function(){
        if (busy) return;
        try {
            await fetch('ai_chat_ajax.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ reset:true, csrf_token: csrf }) });
        } catch(e){}
        // keep the welcome message, drop the rest
        var msgs = thread.querySelectorAll('.ai-msg');
        for (var i = msgs.length - 1; i >= 1; i--) { msgs[i].remove(); }
        input.focus();
    });
})();
</script>
</body>
</html>
