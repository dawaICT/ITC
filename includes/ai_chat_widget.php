<?php
/**
 * Reusable floating AI chat widget renderer.
 *
 * Usage (e.g. from nav_unified.php):
 *   require_once $rootPath . '/includes/ai_chat_widget.php';
 *   wuc_render_ai_chat_widget([
 *       'endpoint' => '/wucportal/includes/ai_staff_chat.php',
 *       'title'    => 'ITC Staff Assistant',
 *       'greeting' => 'Hi! Ask me about the portal.',
 *   ]);
 *
 * Self-contained namespaced CSS/JS (.wuc-aiw-*). One widget per page.
 */

if (!function_exists('wuc_render_ai_chat_widget')) {
    function wuc_render_ai_chat_widget(array $opts): void
    {
        require_once __DIR__ . '/ai_markdown.php';

        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
            }
        }
        $csrf     = (string)$_SESSION['csrf_token'];
        $endpoint = (string)($opts['endpoint'] ?? '');
        $title    = (string)($opts['title'] ?? 'ITC Assistant');
        $greeting = (string)($opts['greeting'] ?? 'Hi! How can I help?');
        $accent   = (string)($opts['accent'] ?? '#6f42c1');
        $scope    = (string)($opts['scope'] ?? '');
        if ($endpoint === '') {
            return;
        }
        $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        echo wuc_ai_output_styles();
        ?>
<style>
.wuc-aiw-fab{position:fixed;right:22px;bottom:22px;width:58px;height:58px;border-radius:50%;background:<?php echo $h($accent); ?>;color:#fff;border:none;box-shadow:0 6px 20px rgba(0,0,0,.25);font-size:22px;cursor:pointer;z-index:13000;display:flex;align-items:center;justify-content:center;transition:transform .15s;}
.wuc-aiw-fab:hover{transform:scale(1.07);}
.wuc-aiw-panel{position:fixed;right:22px;bottom:90px;width:370px;max-width:calc(100vw - 30px);height:520px;max-height:calc(100vh - 120px);background:#fff;border:1px solid #e6e1f0;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.22);z-index:13000;display:none;flex-direction:column;overflow:hidden;}
.wuc-aiw-panel.open{display:flex;}
.wuc-aiw-head{background:<?php echo $h($accent); ?>;color:#fff;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;}
.wuc-aiw-head .t{font-weight:600;font-size:.95rem;display:flex;align-items:center;gap:8px;}
.wuc-aiw-head button{background:transparent;border:none;color:#fff;font-size:15px;cursor:pointer;opacity:.85;}
.wuc-aiw-head button:hover{opacity:1;}
.wuc-aiw-body{flex:1;overflow-y:auto;padding:12px;background:#f7f7fb;}
.wuc-aiw-m{display:flex;gap:8px;margin-bottom:12px;align-items:flex-start;}
.wuc-aiw-m .av{width:28px;height:28px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;background:<?php echo $h($accent); ?>;overflow:hidden;}
.wuc-aiw-m.u{flex-direction:row-reverse;}.wuc-aiw-m.u .av{background:#495057;}
.wuc-aiw-b{max-width:82%;padding:.55rem .8rem;border-radius:12px;font-size:.9rem;line-height:1.55;}
.wuc-aiw-m.a .wuc-aiw-b{background:#fff;border:1px solid #e6e1f0;border-top-left-radius:3px;}
.wuc-aiw-m.u .wuc-aiw-b{background:<?php echo $h($accent); ?>;color:#fff;border-top-right-radius:3px;}
.wuc-aiw-m.u .wuc-aiw-b *{color:#fff !important;}
.wuc-aiw-foot{display:flex;gap:6px;padding:10px;border-top:1px solid #ece8f5;background:#fff;}
.wuc-aiw-foot textarea{flex:1;resize:none;border:1px solid #d7cdee;border-radius:10px;padding:8px 10px;font-size:.9rem;font-family:inherit;max-height:90px;}
.wuc-aiw-foot button{background:<?php echo $h($accent); ?>;color:#fff;border:none;border-radius:10px;width:42px;cursor:pointer;}
.wuc-aiw-foot button:disabled{opacity:.5;cursor:default;}
.wuc-aiw-typing span{display:inline-block;width:6px;height:6px;margin:0 1px;background:<?php echo $h($accent); ?>;border-radius:50%;animation:wucaiwb 1.2s infinite both;}
.wuc-aiw-typing span:nth-child(2){animation-delay:.2s;}.wuc-aiw-typing span:nth-child(3){animation-delay:.4s;}
@keyframes wucaiwb{0%,80%,100%{opacity:.2;}40%{opacity:1;}}
.wuc-aiw-panel .ai-output{font-size:.9rem;}
@media print{.wuc-aiw-fab,.wuc-aiw-panel{display:none !important;}}
</style>

<button type="button" class="wuc-aiw-fab" id="wucAiwFab" aria-label="Open AI assistant" title="<?php echo $h($title); ?>"><img src="/wucportal/assets/images/chatbot/avatar.svg" alt="ITC Assistant" style="width:44px;height:44px;border-radius:50%;object-fit:cover;"></button>
<div class="wuc-aiw-panel" id="wucAiwPanel" role="dialog" aria-label="<?php echo $h($title); ?>">
    <div class="wuc-aiw-head">
        <span class="t"><img src="/wucportal/assets/images/chatbot/avatar.svg" alt="" style="width:22px;height:22px;border-radius:50%;object-fit:cover;vertical-align:middle;"> <?php echo $h($title); ?></span>
        <span>
            <button type="button" id="wucAiwReset" title="New chat"><i class="fas fa-rotate-left"></i></button>
            <button type="button" id="wucAiwClose" title="Close"><i class="fas fa-times"></i></button>
        </span>
    </div>
    <div class="wuc-aiw-body" id="wucAiwBody">
        <div class="wuc-aiw-m a"><div class="av"><img src="/wucportal/assets/images/chatbot/avatar.svg" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;"></div><div class="wuc-aiw-b"><div class="ai-output"><p><?php echo $h($greeting); ?></p></div></div></div>
    </div>
    <div class="wuc-aiw-foot">
        <textarea id="wucAiwInput" rows="1" maxlength="800" placeholder="Type a message…"></textarea>
        <button type="button" id="wucAiwSend" title="Send"><i class="fas fa-paper-plane"></i></button>
    </div>
</div>
<script>
(function(){
    'use strict';
    var csrf = <?php echo json_encode($csrf); ?>;
    var endpoint = <?php echo json_encode($endpoint); ?>;
    var scope = <?php echo json_encode($scope); ?>;
    var fab=document.getElementById('wucAiwFab'), panel=document.getElementById('wucAiwPanel'),
        body=document.getElementById('wucAiwBody'), input=document.getElementById('wucAiwInput'),
        sendBtn=document.getElementById('wucAiwSend'), closeBtn=document.getElementById('wucAiwClose'),
        resetBtn=document.getElementById('wucAiwReset'), busy=false;
    if(!fab||!panel) return;
    function esc(s){return (s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
    function down(){body.scrollTop=body.scrollHeight;}
    function addU(t){var e=document.createElement('div');e.className='wuc-aiw-m u';e.innerHTML='<div class="av"><i class="fas fa-user"></i></div><div class="wuc-aiw-b">'+esc(t)+'</div>';body.appendChild(e);down();}
    var avHtml='<img src="/wucportal/assets/images/chatbot/avatar.svg" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
    function addA(html){var e=document.createElement('div');e.className='wuc-aiw-m a';e.innerHTML='<div class="av">'+avHtml+'</div><div class="wuc-aiw-b">'+html+'</div>';body.appendChild(e);down();}
    function typing(on){var ex=document.getElementById('wucAiwTyp');if(on){if(ex)return;var e=document.createElement('div');e.className='wuc-aiw-m a';e.id='wucAiwTyp';e.innerHTML='<div class="av">'+avHtml+'</div><div class="wuc-aiw-b"><div class="wuc-aiw-typing"><span></span><span></span><span></span></div></div>';body.appendChild(e);down();}else if(ex){ex.remove();}}
    var historyLoaded=false;
    function loadHistory(){
        if(historyLoaded)return; historyLoaded=true;
        fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'history',csrf_token:csrf,scope:scope})})
        .then(function(r){return r.json();})
        .then(function(d){
            if(!d.ok||!d.messages||!d.messages.length)return;
            d.messages.forEach(function(m){
                var e=document.createElement('div');
                if(m.role==='user'){e.className='wuc-aiw-m u';e.innerHTML='<div class="av"><i class="fas fa-user"></i></div><div class="wuc-aiw-b">'+m.html+'</div>';}
                else{e.className='wuc-aiw-m a';e.innerHTML='<div class="av">'+avHtml+'</div><div class="wuc-aiw-b">'+m.html+'</div>';}
                body.appendChild(e);
            });
            body.scrollTop=body.scrollHeight;
        }).catch(function(){});
    }
    fab.addEventListener('click',function(){panel.classList.toggle('open');if(panel.classList.contains('open')){loadHistory();input.focus();}});
    closeBtn.addEventListener('click',function(){panel.classList.remove('open');});
    async function send(){
        if(busy)return; var text=(input.value||'').trim(); if(!text)return;
        busy=true;sendBtn.disabled=true;addU(text);input.value='';typing(true);
        try{
            var r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({message:text,csrf_token:csrf,scope:scope})});
            var d=await r.json();typing(false);
            if(!r.ok||!d.ok){addA('<div class="ai-output"><p style="color:#b02a37;margin:0;">'+esc(d.message||('Error '+r.status))+'</p></div>');}
            else{addA(d.reply_html);}
        }catch(e){typing(false);addA('<div class="ai-output"><p style="color:#b02a37;margin:0;">Could not reach the assistant.</p></div>');}
        finally{busy=false;sendBtn.disabled=false;input.focus();}
    }
    sendBtn.addEventListener('click',send);
    input.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}});
    resetBtn.addEventListener('click',async function(){
        if(busy)return;
        try{await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({reset:true,csrf_token:csrf,scope:scope})});}catch(e){}
        var msgs=body.querySelectorAll('.wuc-aiw-m');for(var i=msgs.length-1;i>=1;i--){msgs[i].remove();}
        historyLoaded=true; // history already wiped — don't reload old messages
        input.focus();
    });
})();
</script>
        <?php
    }
}
