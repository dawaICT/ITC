<?php
/**
 * Floating AI assistant widget for student pages.
 *
 * Included from students/includes/navbar.php so the conversational assistant is
 * available on every student page. Reuses students/ai_chat_ajax.php (and shares
 * the same session conversation history as the full AI Assistant page).
 *
 * Self-contained: namespaced CSS (.wuc-aiw-*) + vanilla JS. No dependencies.
 */

// Don't double up on the dedicated full-page assistant.
if (basename($_SERVER['PHP_SELF'] ?? '') === 'ai_personal_assistant.php') {
    return;
}

require_once dirname(__DIR__, 2) . '/includes/ai_markdown.php';
require_once __DIR__ . '/ai_scope_guard.php';

// Safe student avatar for chat bubbles (session profile image, validated,
// with default-avatar fallback). The navbar caches the filename before this
// include runs.
$_wucAiwAvatar = wuc_ai_student_avatar_url();

if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}
$wucAiwCsrf = (string)$_SESSION['csrf_token'];

// Fetch student's first name for personalised greeting
$_wucAiwFirstName = '';
$_wucAiwSid = (string)($_SESSION['Sid'] ?? '');
if ($_wucAiwSid !== '') {
    try {
        global $db;
        $stmt = $db->prepare("SELECT Fname FROM students WHERE SID = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $_wucAiwSid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $_wucAiwFirstName = trim((string)($row['Fname'] ?? ''));
        }
    } catch (Throwable $e) { /* non-fatal */ }
}
$_wucAiwGreeting = $_wucAiwFirstName !== ''
    ? 'Hello, ' . htmlspecialchars($_wucAiwFirstName, ENT_QUOTES, 'UTF-8') . '. You may ask me about your fees, courses, registration, or results.'
    : 'Hello. You may ask me about your fees, courses, registration, or results.';

echo wuc_ai_output_styles();
?>
<style>
.wuc-aiw-fab {
    position: fixed;
    right: 22px;
    bottom: 22px;
    width: 58px;
    height: 58px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6f42c1, #5a32a3);
    color: #fff;
    border: none;
    box-shadow: 0 6px 20px rgba(111, 66, 193, 0.4);
    font-size: 22px;
    cursor: pointer;
    z-index: 1090;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s;
    animation: wuc-aiw-pulse 2s infinite;
}
.wuc-aiw-fab:hover {
    transform: scale(1.08);
    box-shadow: 0 8px 28px rgba(111, 66, 193, 0.6);
}
.wuc-aiw-fab img {
    transition: transform 0.25s;
}
.wuc-aiw-fab:hover img {
    transform: rotate(5deg) scale(1.02);
}
@keyframes wuc-aiw-pulse {
    0% { box-shadow: 0 0 0 0 rgba(111, 66, 193, 0.5); }
    70% { box-shadow: 0 0 0 15px rgba(111, 66, 193, 0); }
    100% { box-shadow: 0 0 0 0 rgba(111, 66, 193, 0); }
}
.wuc-aiw-panel {
    position: fixed;
    right: 22px;
    bottom: 90px;
    width: 370px;
    max-width: calc(100vw - 30px);
    height: 520px;
    max-height: calc(100vh - 120px);
    background: rgba(255, 255, 255, 0.88);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(111, 66, 193, 0.16);
    border-radius: 16px;
    box-shadow: 0 12px 40px rgba(80, 60, 180, 0.16);
    z-index: 1090;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    opacity: 0;
    visibility: hidden;
    transform: translateY(15px) scale(0.96);
    pointer-events: none;
    transition: opacity 0.25s cubic-bezier(0.4, 0, 0.2, 1), transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), visibility 0.25s;
}
.wuc-aiw-panel.open {
    opacity: 1;
    visibility: visible;
    transform: translateY(0) scale(1);
    pointer-events: auto;
}
.wuc-aiw-head {
    background: linear-gradient(135deg, #6f42c1, #5a32a3);
    color: #fff;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(111, 66, 193, 0.12);
}
.wuc-aiw-head .t {
    font-weight: 600;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    gap: 10px;
}
.wuc-aiw-head button {
    background: transparent;
    border: none;
    color: #fff;
    font-size: 15px;
    cursor: pointer;
    opacity: 0.8;
    margin-left: 10px;
    transition: opacity 0.15s, transform 0.15s;
}
.wuc-aiw-head button:hover {
    opacity: 1;
    transform: scale(1.1);
}
.wuc-aiw-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    background: rgba(250, 249, 253, 0.4);
}
.wuc-aiw-body::-webkit-scrollbar {
    width: 6px;
}
.wuc-aiw-body::-webkit-scrollbar-track {
    background: transparent;
}
.wuc-aiw-body::-webkit-scrollbar-thumb {
    background: rgba(111, 66, 193, 0.2);
    border-radius: 4px;
}
.wuc-aiw-body::-webkit-scrollbar-thumb:hover {
    background: rgba(111, 66, 193, 0.4);
}
.wuc-aiw-m {
    display: flex;
    gap: 10px;
    margin-bottom: 14px;
    align-items: flex-start;
}
.wuc-aiw-m .av {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}
.wuc-aiw-m.a .av { background: #6f42c1; }
.wuc-aiw-m.u { flex-direction: row-reverse; }
.wuc-aiw-m.u .av { background: #495057; }
.wuc-aiw-b {
    max-width: 80%;
    padding: 0.65rem 0.95rem;
    border-radius: 14px;
    font-size: 0.9rem;
    line-height: 1.55;
    box-shadow: 0 2px 12px rgba(111, 66, 193, 0.04);
}
.wuc-aiw-m.a .wuc-aiw-b {
    background: #fff;
    border: 1px solid rgba(230, 225, 240, 0.8);
    border-top-left-radius: 3px;
    color: #212529;
}
.wuc-aiw-m.u .wuc-aiw-b {
    background: #6f42c1;
    color: #fff;
    border-top-right-radius: 3px;
    box-shadow: 0 4px 14px rgba(111, 66, 193, 0.16);
}
.wuc-aiw-m.u .wuc-aiw-b * { color: #fff !important; }
.wuc-aiw-foot {
    display: flex;
    gap: 8px;
    padding: 12px 14px;
    border-top: 1px solid rgba(236, 232, 245, 0.8);
    background: #fff;
}
.wuc-aiw-foot textarea {
    flex: 1;
    resize: none;
    border: 1px solid #d7cdee;
    border-radius: 12px;
    padding: 10px 12px;
    font-size: 0.9rem;
    font-family: inherit;
    max-height: 90px;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.wuc-aiw-foot textarea:focus {
    outline: none;
    border-color: #6f42c1;
    box-shadow: 0 0 0 3px rgba(111, 66, 193, 0.15);
}
.wuc-aiw-foot button {
    background: #6f42c1;
    color: #fff;
    border: none;
    border-radius: 12px;
    width: 44px;
    height: 44px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s, transform 0.15s;
}
.wuc-aiw-foot button:hover:not(:disabled) {
    background: #5a32a3;
    transform: scale(1.05);
}
.wuc-aiw-foot button:disabled {
    opacity: 0.45;
    cursor: default;
}
.wuc-aiw-typing span {
    display: inline-block;
    width: 6px;
    height: 6px;
    margin: 0 2px;
    background: #6f42c1;
    border-radius: 50%;
    animation: wucaiwb 1.2s infinite both;
}
.wuc-aiw-typing span:nth-child(2) { animation-delay: 0.2s; }
.wuc-aiw-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes wucaiwb {
    0%, 80%, 100% { opacity: 0.2; transform: scale(0.8); }
    40% { opacity: 1; transform: scale(1.2); }
}
.wuc-aiw-panel .ai-output { font-size: 0.9rem; }
@media print {
    .wuc-aiw-fab, .wuc-aiw-panel { display: none !important; }
}
</style>

<button type="button" class="wuc-aiw-fab" id="wucAiwFab" aria-label="Open AI assistant" title="Ask the ITC Assistant">
    <img src="/wucportal/assets/images/chatbot/avatar.svg" alt="ITC Assistant" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">
</button>

<div class="wuc-aiw-panel" id="wucAiwPanel" role="dialog" aria-label="AI assistant">
    <div class="wuc-aiw-head">
        <span class="t"><img src="/wucportal/assets/images/chatbot/avatar.svg" alt="" style="width:22px;height:22px;border-radius:50%;object-fit:cover;vertical-align:middle;"> ITC Assistant</span>
        <span>
            <button type="button" id="wucAiwReset" title="New chat"><i class="fas fa-rotate-left"></i></button>
            <button type="button" id="wucAiwClose" title="Close"><i class="fas fa-times"></i></button>
        </span>
    </div>
    <div class="wuc-aiw-body" id="wucAiwBody">
        <div class="wuc-aiw-m a" id="wucAiwWelcome">
            <div class="av"><img src="/wucportal/assets/images/chatbot/avatar.svg" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;"></div>
            <div class="wuc-aiw-b"><div class="ai-output"><p><?php echo $_wucAiwGreeting; ?></p></div></div>
        </div>
    </div>
    <div class="wuc-aiw-foot">
        <textarea id="wucAiwInput" rows="1" maxlength="800" placeholder="Type a message…"></textarea>
        <button type="button" id="wucAiwSend" title="Send"><i class="fas fa-paper-plane"></i></button>
    </div>
</div>

<script>
(function(){
    'use strict';
    var csrf = <?php echo json_encode($wucAiwCsrf); ?>;
    var endpoint = '/wucportal/students/ai_chat_ajax.php';
    var fab = document.getElementById('wucAiwFab');
    var panel = document.getElementById('wucAiwPanel');
    var body = document.getElementById('wucAiwBody');
    var input = document.getElementById('wucAiwInput');
    var sendBtn = document.getElementById('wucAiwSend');
    var closeBtn = document.getElementById('wucAiwClose');
    var resetBtn = document.getElementById('wucAiwReset');
    var busy = false, greeted = false;
    if (!fab || !panel) return;

    function esc(s){ return (s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
    function down(){ body.scrollTop = body.scrollHeight; }
    var avHtml='<img src="/wucportal/assets/images/chatbot/avatar.svg" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
    var stuAvHtml='<img src="'+esc(<?php echo json_encode($_wucAiwAvatar); ?>)+'" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
    function addU(t){ var e=document.createElement('div'); e.className='wuc-aiw-m u'; e.innerHTML='<div class="av">'+stuAvHtml+'</div><div class="wuc-aiw-b">'+esc(t)+'</div>'; body.appendChild(e); down(); }
    function addA(html){ var e=document.createElement('div'); e.className='wuc-aiw-m a'; e.innerHTML='<div class="av">'+avHtml+'</div><div class="wuc-aiw-b">'+html+'</div>'; body.appendChild(e); down(); }
    function typing(on){
        var ex=document.getElementById('wucAiwTyp');
        if(on){ if(ex)return; var e=document.createElement('div'); e.className='wuc-aiw-m a'; e.id='wucAiwTyp'; e.innerHTML='<div class="av">'+avHtml+'</div><div class="wuc-aiw-b"><div class="wuc-aiw-typing"><span></span><span></span><span></span></div></div>'; body.appendChild(e); down(); }
        else if(ex){ ex.remove(); }
    }

    var historyLoaded = false;
    function loadHistory(){
        if(historyLoaded) return;
        historyLoaded = true;
        fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'history',csrf_token:csrf})})
        .then(function(r){return r.json();})
        .then(function(d){
            if(!d.ok||!d.messages||!d.messages.length) return;
            d.messages.forEach(function(m){
                var e=document.createElement('div');
                if(m.role==='user'){
                    e.className='wuc-aiw-m u';
                    e.innerHTML='<div class="av">'+stuAvHtml+'</div><div class="wuc-aiw-b">'+m.html+'</div>';
                } else {
                    e.className='wuc-aiw-m a';
                    e.innerHTML='<div class="av">'+avHtml+'</div><div class="wuc-aiw-b">'+m.html+'</div>';
                }
                body.appendChild(e);
            });
            down();
        }).catch(function(){});
    }
    function toggle(open){
        panel.classList.toggle('open', open);
        try {
            sessionStorage.setItem('wuc_aiw_open', open ? 'true' : 'false');
        } catch (e) {}
        if(open){ loadHistory(); input.focus(); }
    }
    fab.addEventListener('click', function(){ toggle(!panel.classList.contains('open')); });
    closeBtn.addEventListener('click', function(){ toggle(false); });

    try {
        if (sessionStorage.getItem('wuc_aiw_open') === 'true') {
            toggle(true);
        }
    } catch (e) {}

    async function send(){
        if(busy) return;
        var text=(input.value||'').trim();
        if(!text) return;
        busy=true; sendBtn.disabled=true;
        addU(text); input.value=''; typing(true);
        try{
            var r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({message:text,csrf_token:csrf,page:window.location.pathname})});
            var d=await r.json(); typing(false);
            if(!r.ok||!d.ok){ addA('<div class="ai-output"><p style="color:#b02a37;margin:0;">'+esc(d.message||('Error '+r.status))+'</p></div>'); }
            else { addA(d.reply_html); }
        }catch(e){ typing(false); addA('<div class="ai-output"><p style="color:#b02a37;margin:0;">Could not reach the assistant.</p></div>'); }
        finally{ busy=false; sendBtn.disabled=false; input.focus(); }
    }
    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', function(e){ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); send(); } });

    resetBtn.addEventListener('click', async function(){
        if(busy) return;
        try{ await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({reset:true,csrf_token:csrf})}); }catch(e){}
        var msgs=body.querySelectorAll('.wuc-aiw-m');
        for(var i=msgs.length-1;i>=1;i--){ msgs[i].remove(); }
        historyLoaded = true; // new conversation — don't reload previous messages
        input.focus();
    });
})();
</script>
