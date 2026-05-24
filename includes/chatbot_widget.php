<!-- ══ MODIFIED AI CUSTOMER SERVICE CHATBOT (NVIDIA API VERSION) ══════════════ -->
<!-- Include this file at the bottom of footer.php:                          -->
<!--   <?php include("../includes/chatbot_widget.php"); ?>                   -->

<style>
/* ── Floating button ─────────────────────────────────────────────────────── */
#chatbot-fab {
    position: fixed;
    bottom: 28px; right: 28px;
    width: 58px; height: 58px;
    background: linear-gradient(135deg, #ff9900, #e68a00);
    border-radius: 50%;
    box-shadow: 0 4px 16px rgba(255,153,0,.5);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; z-index: 9998;
    border: none;
    transition: transform .2s, box-shadow .2s;
}
#chatbot-fab:hover {
    transform: scale(1.08);
    box-shadow: 0 6px 22px rgba(255,153,0,.6);
}
#chatbot-fab .fab-icon-open  { display: block; }
#chatbot-fab .fab-icon-close { display: none;  }
#chatbot-fab.open .fab-icon-open  { display: none;  }
#chatbot-fab.open .fab-icon-close { display: block; }

/* Notification dot */
#chatbot-fab .fab-dot {
    position: absolute; top: 2px; right: 2px;
    width: 14px; height: 14px;
    background: #e53935; border-radius: 50%;
    border: 2px solid #fff;
    animation: pulse-dot 2s infinite;
}
@keyframes pulse-dot {
    0%, 100% { transform: scale(1);   opacity: 1; }
    50%       { transform: scale(1.2); opacity: .8; }
}

/* ── Chat window ──────────────────────────────────────────────────────────── */
#chatbot-window {
    position: fixed;
    bottom: 100px; right: 28px;
    width: 370px;
    max-height: 560px;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 12px 40px rgba(0,0,0,.18);
    display: flex; flex-direction: column;
    z-index: 9999;
    overflow: hidden;
    /* hidden state */
    opacity: 0;
    transform: translateY(20px) scale(.97);
    pointer-events: none;
    transition: opacity .22s ease, transform .22s ease;
}
#chatbot-window.open {
    opacity: 1;
    transform: translateY(0) scale(1);
    pointer-events: auto;
}

/* ── Header ───────────────────────────────────────────────────────────────── */
#chatbot-header {
    background: linear-gradient(135deg, #131921, #232f3e);
    padding: 14px 18px;
    display: flex; align-items: center; gap: 12px;
    flex-shrink: 0;
}
.chatbot-avatar {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, #ff9900, #ffb347);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.chatbot-avatar i { color: #131921; font-size: 1.1rem; }
.chatbot-header-info { flex: 1; }
.chatbot-header-info .bot-name {
    color: #fff; font-weight: 700; font-size: .95rem; line-height: 1.2;
}
.chatbot-header-info .bot-status {
    color: #ff9900; font-size: .75rem; display: flex; align-items: center; gap: 5px;
}
.status-dot {
    width: 7px; height: 7px; background: #4caf50;
    border-radius: 50%; display: inline-block;
    animation: pulse-dot 2s infinite;
}
#chatbot-clear {
    background: none; border: none; color: #888;
    font-size: .75rem; cursor: pointer; padding: 4px 8px;
    border-radius: 4px; transition: background .15s, color .15s;
    white-space: nowrap;
}
#chatbot-clear:hover { background: rgba(255,255,255,.1); color: #fff; }

/* ── Quick suggestions ────────────────────────────────────────────────────── */
#chatbot-suggestions {
    display: flex; gap: 6px; flex-wrap: wrap;
    padding: 10px 14px 0;
    flex-shrink: 0;
}
.suggestion-chip {
    background: #f0f4ff; color: #1a237e;
    border: 1px solid #c5cae9;
    border-radius: 20px; padding: 4px 12px;
    font-size: .76rem; cursor: pointer;
    transition: background .15s, color .15s;
    white-space: nowrap;
}
.suggestion-chip:hover { background: #ff9900; color: #131921; border-color: #ff9900; }

/* ── Messages ─────────────────────────────────────────────────────────────── */
#chatbot-messages {
    flex: 1;
    overflow-y: auto;
    padding: 14px;
    display: flex; flex-direction: column; gap: 10px;
    scroll-behavior: smooth;
}
#chatbot-messages::-webkit-scrollbar { width: 4px; }
#chatbot-messages::-webkit-scrollbar-thumb { background: #ddd; border-radius: 2px; }

.chat-msg {
    display: flex; align-items: flex-end; gap: 8px;
    animation: msg-in .18s ease;
}
@keyframes msg-in {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}
.chat-msg.bot  { align-self: flex-start; }
.chat-msg.user { align-self: flex-end; flex-direction: row-reverse; }

.msg-avatar {
    width: 28px; height: 28px; border-radius: 50%;
    background: linear-gradient(135deg, #ff9900, #ffb347);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: .7rem; color: #131921;
}
.msg-bubble {
    max-width: 78%;
    padding: 9px 13px;
    border-radius: 16px;
    font-size: .86rem; line-height: 1.45;
}
.chat-msg.bot  .msg-bubble {
    background: #f3f4f6; color: #222;
    border-bottom-left-radius: 4px;
}
.chat-msg.user .msg-bubble {
    background: linear-gradient(135deg, #ff9900, #e68a00);
    color: #131921; font-weight: 500;
    border-bottom-right-radius: 4px;
}
.msg-time {
    font-size: .68rem; color: #aaa;
    margin-top: 3px; text-align: right;
}

/* Typing indicator */
.typing-indicator {
    display: flex; align-items: center; gap: 4px;
    padding: 10px 14px;
    background: #f3f4f6;
    border-radius: 16px; border-bottom-left-radius: 4px;
    width: fit-content;
}
.typing-indicator span {
    width: 7px; height: 7px; background: #aaa;
    border-radius: 50%; animation: typing-bounce .9s infinite;
}
.typing-indicator span:nth-child(2) { animation-delay: .15s; }
.typing-indicator span:nth-child(3) { animation-delay: .3s;  }
@keyframes typing-bounce {
    0%, 60%, 100% { transform: translateY(0); }
    30%            { transform: translateY(-6px); }
}

/* ── Input area ───────────────────────────────────────────────────────────── */
#chatbot-input-area {
    display: flex; align-items: center; gap: 8px;
    padding: 12px 14px;
    border-top: 1px solid #eee;
    flex-shrink: 0;
    background: #fafafa;
}
#chatbot-input {
    flex: 1; border: 1px solid #ddd; border-radius: 22px;
    padding: 9px 16px; font-size: .88rem;
    outline: none; resize: none;
    background: #fff;
    transition: border-color .15s;
    max-height: 90px; overflow-y: auto;
    font-family: inherit;
}
#chatbot-input:focus { border-color: #ff9900; box-shadow: 0 0 0 2px rgba(255,153,0,.15); }
#chatbot-send {
    width: 38px; height: 38px; border-radius: 50%;
    background: linear-gradient(135deg, #ff9900, #e68a00);
    border: none; color: #131921;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; flex-shrink: 0;
    transition: transform .15s, box-shadow .15s;
}
#chatbot-send:hover { transform: scale(1.08); box-shadow: 0 2px 8px rgba(255,153,0,.4); }
#chatbot-send:disabled { background: #ddd; color: #999; cursor: not-allowed; transform: none; box-shadow: none; }

/* ── Footer branding ──────────────────────────────────────────────────────── */
#chatbot-footer {
    text-align: center; padding: 6px;
    font-size: .68rem; color: #bbb;
    border-top: 1px solid #f0f0f0;
    background: #fafafa;
    flex-shrink: 0;
}

/* ── Mobile ───────────────────────────────────────────────────────────────── */
@media (max-width: 480px) {
    #chatbot-window {
        width: calc(100vw - 20px);
        right: 10px; bottom: 90px;
        max-height: 70vh;
    }
    #chatbot-fab { bottom: 18px; right: 18px; }
}
</style>

<!-- Floating button -->
<button id="chatbot-fab" aria-label="Open customer support chat">
    <i class="fas fa-headset fa-lg text-dark fab-icon-open"></i>
    <i class="fas fa-times fa-lg text-dark fab-icon-close"></i>
    <span class="fab-dot"></span>
</button>

<!-- Chat window -->
<div id="chatbot-window" role="dialog" aria-label="Customer Service Chat">

    <!-- Header -->
    <div id="chatbot-header">
        <div class="chatbot-avatar"><i class="fas fa-robot"></i></div>
        <div class="chatbot-header-info">
            <div class="bot-name">E-Shop BD Support</div>
            <div class="bot-status"><span class="status-dot"></span> AI Assistant · Always Online</div>
        </div>
        <button id="chatbot-clear" title="Clear chat">
            <i class="fas fa-rotate-right me-1"></i>New
        </button>
    </div>

    <!-- Quick suggestion chips -->
    <div id="chatbot-suggestions">
        <span class="suggestion-chip" data-msg="Track my order">📦 Track Order</span>
        <span class="suggestion-chip" data-msg="What is your return policy?">🔄 Returns</span>
        <span class="suggestion-chip" data-msg="How do I pay?">💳 Payment</span>
        <span class="suggestion-chip" data-msg="Delivery time in Dhaka">🚚 Delivery</span>
    </div>

    <!-- Messages -->
    <div id="chatbot-messages"></div>

    <!-- Input -->
    <div id="chatbot-input-area">
        <textarea id="chatbot-input" placeholder="Ask me anything…" rows="1"></textarea>
        <button id="chatbot-send" aria-label="Send message">
            <i class="fas fa-paper-plane"></i>
        </button>
    </div>

    <div id="chatbot-footer">Powered by E-Shop BD AI · NVIDIA</div>
</div>

<script>
(function () {
    const fab        = document.getElementById('chatbot-fab');
    const win        = document.getElementById('chatbot-window');
    const msgs       = document.getElementById('chatbot-messages');
    const input      = document.getElementById('chatbot-input');
    const sendBtn    = document.getElementById('chatbot-send');
    const clearBtn   = document.getElementById('chatbot-clear');
    const suggestions = document.querySelectorAll('.suggestion-chip');

    // Depth of conversation history sent to the API
    const MAX_HISTORY = 10;
    let history = [];
    let isTyping = false;

    // ── Helpers ─────────────────────────────────────────────────────────────
    function getTime() {
        return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function scrollBottom() {
        msgs.scrollTop = msgs.scrollHeight;
    }

    function appendMsg(role, text) {
        const wrap = document.createElement('div');
        wrap.className = `chat-msg ${role}`;

        const avatar = document.createElement('div');
        avatar.className = 'msg-avatar';
        avatar.innerHTML = role === 'bot'
            ? '<i class="fas fa-robot"></i>'
            : '<i class="fas fa-user"></i>';

        const bubble = document.createElement('div');
        bubble.className = 'msg-bubble';
        bubble.innerHTML = text.replace(/\n/g, '<br>');

        const timeEl = document.createElement('div');
        timeEl.className = 'msg-time';
        timeEl.textContent = getTime();
        bubble.appendChild(timeEl);

        wrap.appendChild(avatar);
        wrap.appendChild(bubble);
        msgs.appendChild(wrap);
        scrollBottom();
        return bubble;
    }

    function showTyping() {
        const wrap = document.createElement('div');
        wrap.className = 'chat-msg bot';
        wrap.id = 'typing-wrap';

        const avatar = document.createElement('div');
        avatar.className = 'msg-avatar';
        avatar.innerHTML = '<i class="fas fa-robot"></i>';

        const indicator = document.createElement('div');
        indicator.className = 'typing-indicator';
        indicator.innerHTML = '<span></span><span></span><span></span>';

        wrap.appendChild(avatar);
        wrap.appendChild(indicator);
        msgs.appendChild(wrap);
        scrollBottom();
    }

    function hideTyping() {
        const el = document.getElementById('typing-wrap');
        if (el) el.remove();
    }

    function setLoading(on) {
        isTyping = on;
        sendBtn.disabled = on;
        input.disabled   = on;
    }

    // ── Greeting ─────────────────────────────────────────────────────────────
    function showGreeting() {
        msgs.innerHTML = '';
        history = [];

        // Note: PHP session name is handled if this is in a PHP file
        const userName = <?php echo isset($_SESSION['name'])
            ? json_encode(explode(' ', $_SESSION['name'])[0])
            : 'null'; ?>;

        const greeting = userName
            ? `Hi **${userName}**! 👋 I'm your E-Shop BD assistant.\n\nI can help with orders, returns, payments, delivery, and anything about our products. What can I do for you?`
            : `Hi there! 👋 I'm the E-Shop BD AI assistant.\n\nI can help with orders, returns, payments, and delivery questions. What can I help you with today?`;

        appendMsg('bot', greeting.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>'));
    }

    // ── Send message ─────────────────────────────────────────────────────────
    async function sendMessage(text) {
        text = text.trim();
        if (!text || isTyping) return;

        // Hide suggestions after first real message
        document.getElementById('chatbot-suggestions').style.display = 'none';

        appendMsg('user', text);
        input.value = '';
        input.style.height = 'auto';

        history.push({ role: 'user', content: text });
        if (history.length > MAX_HISTORY) history = history.slice(-MAX_HISTORY);

        setLoading(true);
        showTyping();

        try {
            const res = await fetch('../api/chatbot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ messages: history })
            });

            const data = await res.json();

            hideTyping();

            if (data.error) {
                appendMsg('bot', '⚠️ Sorry, something went wrong. Please try again.');
            } else {
                const reply = data.reply || 'Sorry, I didn\'t catch that.';
                appendMsg('bot', reply.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                                       .replace(/\*(.*?)\*/g, '<em>$1</em>'));
                history.push({ role: 'assistant', content: reply });
                if (history.length > MAX_HISTORY) history = history.slice(-MAX_HISTORY);
            }
        } catch (err) {
            hideTyping();
            appendMsg('bot', '⚠️ Connection error. Please check your internet and try again.');
        } finally {
            setLoading(false);
        }
    }

    // ── Event listeners ───────────────────────────────────────────────────────
    fab.addEventListener('click', () => {
        const opening = !win.classList.contains('open');
        fab.classList.toggle('open');
        win.classList.toggle('open');
        if (opening && msgs.children.length === 0) showGreeting();
        if (opening) setTimeout(() => input.focus(), 250);
    });

    sendBtn.addEventListener('click', () => sendMessage(input.value));

    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(input.value);
        }
    });

    // Auto-resize textarea
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 90) + 'px';
    });

    clearBtn.addEventListener('click', showGreeting);

    suggestions.forEach(chip => {
        chip.addEventListener('click', () => sendMessage(chip.getAttribute('data-msg')));
    });

    // Initial greeting if open by default (optional)
})();
</script>
