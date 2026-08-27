<?php
/**
 * User AI Assistant Module - Gives parishioners guided help for requests, schedules, and account questions.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
requirePermission('ai.parishioner.use');

$page_title = 'TUGON AI Parish Assistant';
$body_extra_class = 'user-ai-chat-page';
?>
<?php include '../templates/header.php'; ?>

<style>
    :root {
        --ai-blue: #1e3a8a;
        --ai-blue-soft: #dbeafe;
        --ai-gold: #d4af37;
        --ai-ink: #0f172a;
        --ai-muted: #64748b;
        --ai-line: #e2e8f0;
        --ai-white: #f8fafc;
        --ai-card: rgba(255, 255, 255, 0.92);
    }

    .tugon-ai-page {
        min-height: calc(100vh - 90px);
        padding: clamp(18px, 3vw, 34px);
        background:
            radial-gradient(circle at top left, rgba(212, 175, 55, 0.14), transparent 28%),
            linear-gradient(135deg, #f8fafc 0%, #eef4ff 48%, #f8fafc 100%);
    }

    .tugon-ai-shell {
        width: min(100%, 1180px);
        margin: 0 auto;
        display: grid;
        gap: 18px;
    }

    .tugon-ai-card {
        overflow: hidden;
        border: 1px solid rgba(226, 232, 240, 0.92);
        border-radius: 20px;
        background: var(--ai-card);
        box-shadow: 0 24px 70px rgba(15, 23, 42, 0.12);
        backdrop-filter: blur(18px);
    }

    .tugon-ai-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 22px clamp(18px, 3vw, 28px);
        color: #ffffff;
        background:
            linear-gradient(135deg, rgba(30, 58, 138, 0.98), rgba(30, 64, 175, 0.94)),
            radial-gradient(circle at top right, rgba(212, 175, 55, 0.34), transparent 32%);
    }

    .tugon-ai-identity {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 0;
    }

    .tugon-ai-avatar {
        width: 58px;
        height: 58px;
        border-radius: 18px;
        display: grid;
        place-items: center;
        flex: 0 0 auto;
        color: #172554;
        background: linear-gradient(135deg, #fff7d6, var(--ai-gold));
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.5), 0 16px 34px rgba(15, 23, 42, 0.22);
        font-size: 1.45rem;
    }

    .tugon-ai-title-block h1 {
        margin: 0;
        color: #ffffff;
        font-size: clamp(1.35rem, 2.5vw, 2rem);
        font-weight: 850;
        letter-spacing: 0;
    }

    .tugon-ai-title-block p {
        margin: 4px 0 0;
        color: rgba(248, 250, 252, 0.82);
        font-size: 0.95rem;
    }

    .tugon-ai-status {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 8px;
    }

    .tugon-ai-mobile-back,
    .tugon-ai-footer-actions {
        display: none;
    }

    .ai-status-pill,
    .ai-icon-btn {
        border: 1px solid rgba(255, 255, 255, 0.22);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.12);
        color: #ffffff;
        min-height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 8px 12px;
        font-weight: 800;
        font-size: 0.84rem;
    }

    .ai-status-dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        background: #22c55e;
        box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.18);
    }

    .ai-icon-btn {
        width: 38px;
        padding: 0;
        cursor: pointer;
        transition: transform 0.18s ease, background 0.18s ease;
    }

    .ai-icon-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: translateY(-1px);
    }

    .tugon-ai-body {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 290px;
        gap: 18px;
        padding: clamp(16px, 2.4vw, 24px);
    }

    .tugon-ai-main {
        min-width: 0;
        display: grid;
        grid-template-rows: auto minmax(430px, 58vh) auto;
        gap: 14px;
    }

    .tugon-ai-welcome {
        border: 1px solid var(--ai-line);
        border-radius: 18px;
        padding: 18px;
        background:
            linear-gradient(135deg, rgba(248, 250, 252, 0.92), rgba(255, 255, 255, 0.96)),
            radial-gradient(circle at right, rgba(212, 175, 55, 0.12), transparent 30%);
    }

    .tugon-ai-welcome h2 {
        margin: 0 0 7px;
        color: var(--ai-ink);
        font-size: 1.22rem;
        font-weight: 850;
    }

    .tugon-ai-welcome p {
        margin: 0 0 13px;
        color: var(--ai-muted);
    }

    .ai-quick-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .ai-chip {
        border: 1px solid #cbd5e1;
        border-radius: 999px;
        background: #ffffff;
        color: #1e3a8a;
        min-height: 36px;
        padding: 8px 12px;
        font-weight: 800;
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .ai-chip:hover {
        border-color: var(--ai-gold);
        box-shadow: 0 10px 22px rgba(30, 58, 138, 0.12);
        transform: translateY(-1px);
    }

    .tugon-chat-log {
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 14px;
        padding: 16px;
        border: 1px solid var(--ai-line);
        border-radius: 18px;
        background: rgba(248, 250, 252, 0.78);
        scroll-behavior: smooth;
    }

    .ai-message {
        display: grid;
        grid-template-columns: 36px minmax(0, 1fr);
        gap: 10px;
        align-items: end;
        animation: aiMessageIn 0.24s ease both;
    }

    .ai-message.user {
        grid-template-columns: minmax(0, 1fr) 36px;
    }

    .ai-message-avatar {
        width: 36px;
        height: 36px;
        border-radius: 14px;
        display: grid;
        place-items: center;
        color: #ffffff;
        background: var(--ai-blue);
        box-shadow: 0 8px 18px rgba(30, 58, 138, 0.18);
    }

    .ai-message.user .ai-message-avatar {
        grid-column: 2;
        background: #334155;
    }

    .ai-bubble {
        width: fit-content;
        max-width: min(720px, 100%);
        padding: 13px 14px;
        border-radius: 18px 18px 18px 8px;
        color: var(--ai-ink);
        background: #ffffff;
        border: 1px solid var(--ai-line);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
    }

    .ai-message.user .ai-bubble {
        justify-self: end;
        grid-column: 1;
        grid-row: 1;
        color: #ffffff;
        background: linear-gradient(135deg, var(--ai-blue), #2563eb);
        border-color: rgba(37, 99, 235, 0.24);
        border-radius: 18px 18px 8px 18px;
    }

    .ai-bubble strong {
        display: block;
        margin-bottom: 5px;
        font-weight: 850;
    }

    .ai-bubble p {
        margin: 0;
        line-height: 1.55;
    }

    .ai-bubble ol {
        margin: 10px 0 0;
        padding-left: 18px;
    }

    .ai-meta {
        display: flex;
        align-items: center;
        gap: 9px;
        margin-top: 10px;
        color: #94a3b8;
        font-size: 0.76rem;
        font-weight: 700;
    }

    .ai-message.user .ai-meta {
        color: rgba(255, 255, 255, 0.74);
    }

    .ai-copy-btn {
        border: 0;
        padding: 0;
        background: transparent;
        color: inherit;
        font-weight: 800;
        cursor: pointer;
    }

    .ai-suggestions {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        margin-top: 12px;
    }

    .ai-suggestion-label {
        width: 100%;
        color: var(--ai-muted);
        font-size: 0.78rem;
        font-weight: 800;
    }

    .ai-typing-line {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        color: var(--ai-muted);
        font-weight: 800;
    }

    .ai-typing-dots {
        display: inline-flex;
        gap: 4px;
    }

    .ai-typing-dots span {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--ai-gold);
        animation: aiTyping 0.9s infinite ease-in-out;
    }

    .ai-typing-dots span:nth-child(2) {
        animation-delay: 0.12s;
    }

    .ai-typing-dots span:nth-child(3) {
        animation-delay: 0.24s;
    }

    .tugon-ai-form {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto auto;
        gap: 10px;
        align-items: end;
        padding: 12px;
        border: 1px solid var(--ai-line);
        border-radius: 18px;
        background: #ffffff;
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
    }

    .tugon-ai-form textarea {
        min-height: 48px;
        max-height: 130px;
        resize: vertical;
        border: 0;
        outline: 0;
        padding: 12px 10px;
        color: var(--ai-ink);
        background: transparent;
    }

    .ai-send-btn,
    .ai-voice-btn {
        border: 0;
        border-radius: 14px;
        min-height: 46px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        font-weight: 850;
        cursor: pointer;
    }

    .ai-send-btn {
        padding: 0 18px;
        color: #ffffff;
        background: linear-gradient(135deg, var(--ai-blue), #2563eb);
        box-shadow: 0 12px 24px rgba(30, 58, 138, 0.22);
    }

    .ai-voice-btn {
        width: 46px;
        color: var(--ai-blue);
        background: var(--ai-blue-soft);
    }

    .tugon-ai-side {
        display: grid;
        align-content: start;
        gap: 14px;
    }

    .ai-side-panel {
        border: 1px solid var(--ai-line);
        border-radius: 18px;
        padding: 16px;
        background: #ffffff;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
    }

    .ai-side-panel h3 {
        margin: 0 0 10px;
        color: var(--ai-ink);
        font-size: 0.98rem;
        font-weight: 850;
    }

    .ai-scope-list {
        display: grid;
        gap: 8px;
        margin: 0;
        padding: 0;
        list-style: none;
        color: #475569;
        font-weight: 700;
        font-size: 0.88rem;
    }

    .ai-scope-list li {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .ai-scope-list i {
        color: var(--ai-gold);
    }

    .ai-dark-mode {
        --ai-ink: #e5e7eb;
        --ai-muted: #94a3b8;
        --ai-line: rgba(148, 163, 184, 0.24);
        --ai-card: rgba(15, 23, 42, 0.92);
        background:
            radial-gradient(circle at top left, rgba(212, 175, 55, 0.1), transparent 28%),
            linear-gradient(135deg, #020617, #0f172a);
    }

    .ai-dark-mode .tugon-ai-card,
    .ai-dark-mode .tugon-ai-welcome,
    .ai-dark-mode .tugon-chat-log,
    .ai-dark-mode .tugon-ai-form,
    .ai-dark-mode .ai-side-panel,
    .ai-dark-mode .ai-bubble {
        background: rgba(15, 23, 42, 0.86);
        color: var(--ai-ink);
    }

    .ai-dark-mode .ai-chip,
    .ai-dark-mode .ai-voice-btn {
        background: rgba(30, 41, 59, 0.92);
        color: #bfdbfe;
        border-color: rgba(148, 163, 184, 0.34);
    }

    .ai-dark-mode .tugon-ai-form textarea,
    .ai-dark-mode .ai-side-panel h3,
    .ai-dark-mode .tugon-ai-welcome h2 {
        color: var(--ai-ink);
    }

    .tugon-ai-card.is-minimized .tugon-ai-body {
        display: none;
    }

    @keyframes aiTyping {
        0%, 80%, 100% {
            transform: translateY(0);
            opacity: 0.45;
        }
        40% {
            transform: translateY(-4px);
            opacity: 1;
        }
    }

    @keyframes aiMessageIn {
        from {
            opacity: 0;
            transform: translateY(8px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @media (max-width: 980px) {
        .tugon-ai-body {
            grid-template-columns: 1fr;
        }

        .tugon-ai-side {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 767px) {
        .tugon-ai-page {
            padding: 0;
            min-height: 100dvh;
            height: 100dvh;
        }

        .tugon-ai-shell,
        .tugon-ai-card {
            height: 100dvh;
            border-radius: 0;
            border: 0;
        }

        .tugon-ai-header {
            padding: max(8px, env(safe-area-inset-top)) 14px 8px;
        }

        .tugon-ai-main {
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .tugon-ai-side {
            display: none;
        }

        .tugon-ai-form {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px max(10px, env(safe-area-inset-bottom)) 12px;
            border-radius: 0;
            border-left: 0;
            border-right: 0;
            border-bottom: 0;
        }

        .ai-send-btn {
            width: 38px;
            height: 38px;
            min-width: 38px;
            min-height: 38px;
            padding: 0;
            border-radius: 50%;
        }

        .ai-send-btn span {
            display: none;
        }
    }
</style>
<link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">

<main class="tugon-ai-page" id="tugonAiPage">
    <section class="tugon-ai-shell">
        <div class="tugon-ai-card" id="tugonAiCard">
            <header class="tugon-ai-header">
                <a class="tugon-ai-mobile-back" href="index.php" aria-label="Back to dashboard menu">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </a>
                <div class="tugon-ai-identity">
                    <div class="tugon-ai-avatar" aria-hidden="true">
                        <i class="fas fa-church"></i>
                    </div>
                    <div class="tugon-ai-title-block">
                        <h1>TUGON AI Parish Assistant</h1>
                        <p>Your Digital Parish Companion</p>
                    </div>
                </div>
                <div class="tugon-ai-status">
                    <span class="ai-status-pill"><span class="ai-status-dot"></span> Online</span>
                    <span class="ai-status-pill" id="aiHeaderStatus">Ready to help with parish services</span>
                    <button type="button" class="ai-icon-btn" id="aiDarkToggle" title="Toggle dark mode" aria-label="Toggle dark mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <button type="button" class="ai-icon-btn" id="aiClearBtn" title="Clear conversation" aria-label="Clear conversation">
                        <i class="fas fa-trash-can"></i>
                    </button>
                    <button type="button" class="ai-icon-btn" id="aiMinimizeBtn" title="Minimize chat" aria-label="Minimize chat">
                        <i class="fas fa-minus"></i>
                    </button>
                </div>
            </header>

            <div class="tugon-ai-body">
                <section class="tugon-ai-main" aria-label="TUGON AI chat">
                    <div class="tugon-ai-welcome" id="aiWelcome">
                        <h2>Welcome to TUGON AI Parish Assistant</h2>
                        <p>I can help you with certificate requests, Mass schedules, parish events, sacramental requirements, announcements, frequently asked questions, and request tracking.</p>
                    </div>

                    <div class="tugon-chat-log" id="aiChatLog" aria-live="polite"></div>

                    <nav class="tugon-ai-footer-actions" aria-label="Quick parish assistant actions">
                        <span class="tugon-ai-footer-actions-label"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Quick Actions</span>
                        <div class="ai-quick-grid" id="aiQuickActions"></div>
                    </nav>

                    <form class="tugon-ai-form" id="aiChatForm">
                        <label class="visually-hidden" for="aiMessage">Ask a parish question</label>
                        <textarea id="aiMessage" rows="1" placeholder="Type your message..."></textarea>
                        <button class="ai-voice-btn" type="button" id="aiVoiceBtn" title="Voice input" aria-label="Voice input">
                            <i class="fas fa-microphone"></i>
                        </button>
                        <button class="ai-send-btn" type="submit">
                            <i class="fas fa-paper-plane"></i>
                            <span>Send</span>
                        </button>
                    </form>
                </section>

                <aside class="tugon-ai-side" aria-label="Assistant information">
                    <div class="ai-side-panel">
                        <h3><i class="fas fa-wand-magic-sparkles"></i> Quick Actions</h3>
                        <div class="ai-quick-grid" id="aiSideActions"></div>
                    </div>
                    <div class="ai-side-panel">
                        <h3><i class="fas fa-circle-info"></i> I Can Help With</h3>
                        <ul class="ai-scope-list">
                            <li><i class="fas fa-file-lines"></i> Certificate Requests</li>
                            <li><i class="fas fa-water"></i> Baptism and Confirmation</li>
                            <li><i class="fas fa-ring"></i> Marriage Requirements</li>
                            <li><i class="fas fa-calendar-days"></i> Mass Schedules and Events</li>
                            <li><i class="fas fa-bullhorn"></i> Parish Announcements</li>
                            <li><i class="fas fa-clipboard-check"></i> Request Status Tracking</li>
                        </ul>
                    </div>
                </aside>
            </div>
        </div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const page = document.getElementById('tugonAiPage');
    const card = document.getElementById('tugonAiCard');
    const form = document.getElementById('aiChatForm');
    const input = document.getElementById('aiMessage');
    const log = document.getElementById('aiChatLog');
    const headerStatus = document.getElementById('aiHeaderStatus');
    const quickActions = document.getElementById('aiQuickActions');
    const sideActions = document.getElementById('aiSideActions');
    const darkToggle = document.getElementById('aiDarkToggle');
    const clearBtn = document.getElementById('aiClearBtn');
    const minimizeBtn = document.getElementById('aiMinimizeBtn');
    const voiceBtn = document.getElementById('aiVoiceBtn');
    const sendBtn = form.querySelector('.ai-send-btn');
    const conversationHistory = [];
    let assistantCsrfToken = <?php echo json_encode(generateCsrfToken()); ?>;

    const actions = [
        {label: 'Baptism Requirements', prompt: 'What are the Baptism requirements?', icon: 'fa-water'},
        {label: 'Marriage Requirements', prompt: 'What are the Marriage requirements?', icon: 'fa-ring'},
        {label: 'Confirmation Requirements', prompt: 'What are the Confirmation requirements?', icon: 'fa-dove'},
        {label: 'Mass Schedule', prompt: 'What is the Mass schedule?', icon: 'fa-calendar-days'},
        {label: 'Request Certificate', prompt: 'How do I request a certificate?', icon: 'fa-file-lines'},
        {label: 'Track Request Status', prompt: 'How can I track my request status?', icon: 'fa-clipboard-check'},
        {label: 'Parish Announcements', prompt: 'Where can I view parish announcements?', icon: 'fa-bullhorn'},
        {label: 'Contact Parish Office', prompt: 'What are the parish office hours and contact guidance?', icon: 'fa-phone'}
    ];

    const relatedSuggestions = {
        baptism: ['Marriage Requirements', 'Confirmation Requirements', 'Request Certificate'],
        marriage: ['Baptism Requirements', 'Mass Schedule', 'Contact Parish Office'],
        confirmation: ['Baptism Requirements', 'Request Certificate', 'Parish Announcements'],
        schedule: ['Mass Schedule', 'Parish Announcements', 'Contact Parish Office'],
        certificate: ['Request Certificate', 'Track Request Status', 'Baptism Requirements'],
        status: ['Track Request Status', 'Notifications', 'Contact Parish Office'],
        announcements: ['Parish Announcements', 'Mass Schedule', 'Parish Events']
    };

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function(char) {
            return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'})[char];
        });
    }

    function currentTime() {
        return new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    }

    function textFromHtml(html) {
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        return tmp.textContent || tmp.innerText || '';
    }

    function scrollLatest() {
        log.scrollTop = log.scrollHeight;
    }

    function makeChip(action) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ai-chip';
        button.dataset.aiPrompt = action.prompt;
        button.innerHTML = '<i class="fas ' + action.icon + '"></i>' + escapeHtml(action.label);
        return button;
    }

    function renderQuickActions() {
        quickActions.innerHTML = '';
        sideActions.innerHTML = '';
        actions.forEach(function(action, index) {
            quickActions.appendChild(makeChip(action));
            if (index < 6) {
                sideActions.appendChild(makeChip(action));
            }
        });
    }

    function suggestionSet(message) {
        const q = String(message || '').toLowerCase();
        let key = 'certificate';
        if (q.includes('baptism')) key = 'baptism';
        if (q.includes('marriage') || q.includes('wedding')) key = 'marriage';
        if (q.includes('confirmation')) key = 'confirmation';
        if (q.includes('mass') || q.includes('schedule') || q.includes('event')) key = 'schedule';
        if (q.includes('status') || q.includes('track')) key = 'status';
        if (q.includes('announcement')) key = 'announcements';
        return relatedSuggestions[key] || relatedSuggestions.certificate;
    }

    function typingDelayFor(text) {
        const length = String(text || '').length;
        return Math.min(1200, Math.max(420, length * 8));
    }

    function typeText(target, text, done) {
        const value = String(text || '');
        let index = 0;
        const speed = Math.max(10, Math.min(24, Math.floor(900 / Math.max(value.length, 1))));
        target.textContent = '';
        function tick() {
            target.textContent = value.slice(0, index);
            scrollLatest();
            index += 1;
            if (index <= value.length) {
                window.setTimeout(tick, speed);
            } else if (typeof done === 'function') {
                done();
            }
        }
        tick();
    }

    function addMessage(type, options) {
        const title = options.title || (type === 'user' ? 'You' : 'TUGON AI Parish Assistant');
        const body = options.body || '';
        const steps = options.steps || [];
        const suggestions = options.suggestions || [];
        const isLoading = options.loading || false;
        const shouldStream = options.stream || false;
        const item = document.createElement('div');
        item.className = 'ai-message ' + type + (isLoading ? ' loading' : '');
        const icon = type === 'user' ? 'fa-user' : 'fa-church';
        const stepsHtml = steps.length ? '<ol>' + steps.map(function(step) { return '<li>' + escapeHtml(step) + '</li>'; }).join('') + '</ol>' : '';
        const bodyHtml = isLoading
            ? '<div class="ai-typing-line">TUGON AI is typing <span class="ai-typing-dots"><span></span><span></span><span></span></span></div>'
            : '<p><span class="ai-response-text">' + (shouldStream ? '' : escapeHtml(body)) + '</span></p>' + stepsHtml;
        const suggestionHtml = suggestions.length && !isLoading
            ? '<div class="ai-suggestions"><span class="ai-suggestion-label">You may also need:</span>' + suggestions.map(function(label) {
                const found = actions.find(function(action) { return action.label === label; }) || {label: label, prompt: label, icon: 'fa-circle-question'};
                return '<button type="button" class="ai-chip" data-ai-prompt="' + escapeHtml(found.prompt) + '"><i class="fas ' + found.icon + '"></i>' + escapeHtml(found.label) + '</button>';
            }).join('') + '</div>'
            : '';

        item.innerHTML =
            '<div class="ai-message-avatar"><i class="fas ' + icon + '"></i></div>' +
            '<div class="ai-bubble">' +
                '<strong>' + escapeHtml(title) + '</strong>' +
                bodyHtml +
                suggestionHtml +
                '<div class="ai-meta"><span>' + currentTime() + '</span><button type="button" class="ai-copy-btn">Copy</button></div>' +
            '</div>';
        log.appendChild(item);
        scrollLatest();
        if (shouldStream) {
            typeText(item.querySelector('.ai-response-text'), body, function() {
                input.focus();
            });
        }
        return item;
    }

    function setTyping(isTyping) {
        headerStatus.textContent = isTyping ? 'TUGON AI is typing...' : 'Ready to help with parish services';
    }

    function removeLoading() {
        const loading = log.querySelector('.ai-message.loading');
        if (loading) loading.remove();
    }

    function refreshAssistantCsrfToken() {
        return fetch('../api/csrf-token.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (!data.success || !data.token) {
                throw new Error(data.error || 'Unable to refresh the secure session token.');
            }
            assistantCsrfToken = data.token;
            return assistantCsrfToken;
        });
    }

    function postAssistantMessage(message, mode, retried) {
        return fetch('../api/ai-assistant.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': assistantCsrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({message: message, mode: mode || 'chat', conversation: conversationHistory.slice(-8)})
        })
        .then(function(response) {
            return response.json().then(function(data) {
                if (response.status === 403 && !retried) {
                    return refreshAssistantCsrfToken().then(function() {
                        return postAssistantMessage(message, mode, true);
                    });
                }
                return data;
            });
        });
    }

    function askAssistant(message, mode) {
        if (!message) return;
        addMessage('user', {title: 'You', body: message});
        conversationHistory.push({role: 'user', content: message});
        addMessage('assistant', {loading: true});
        setTyping(true);
        sendBtn.disabled = true;

        postAssistantMessage(message, mode, false)
        .then(function(data) {
            const answer = data.success
                ? (data.answer || 'Thank you for your question. I would be glad to assist you with parish services.')
                : (data.message || 'I am unable to answer right now. Please try again.');
            const title = data.success
                ? (data.guidance && data.guidance.title ? data.guidance.title : 'Here is the information you need')
                : 'TUGON AI Parish Assistant';
            window.setTimeout(function() {
                removeLoading();
                setTyping(false);
                sendBtn.disabled = false;
                if (!data.success) {
                    addMessage('assistant', {
                        title: title,
                        body: answer,
                        suggestions: ['Mass Schedule', 'Request Certificate', 'Contact Parish Office'],
                        stream: true
                    });
                    conversationHistory.push({role: 'assistant', content: answer});
                    return;
                }
                addMessage('assistant', {
                    title: title,
                    body: answer,
                    steps: data.guidance && data.guidance.steps ? data.guidance.steps : [],
                    suggestions: suggestionSet(message),
                    stream: true
                });
                conversationHistory.push({role: 'assistant', content: answer});
            }, typingDelayFor(answer));
        })
        .catch(function() {
            const answer = 'Unable to reach the chatbot endpoint. Please try again.';
            window.setTimeout(function() {
                removeLoading();
                setTyping(false);
                sendBtn.disabled = false;
                addMessage('assistant', {
                    title: 'Connection Issue',
                    body: answer,
                    suggestions: ['Mass Schedule', 'Request Certificate', 'Contact Parish Office'],
                    stream: true
                });
            }, typingDelayFor(answer));
        });
    }

    renderQuickActions();

    addMessage('assistant', {
        title: 'Welcome to TUGON AI Parish Assistant',
        body: 'Welcome to TUGON AI Parish Assistant. How may I assist you today?',
        suggestions: ['Baptism Requirements', 'Mass Schedule', 'Request Certificate']
    });
    conversationHistory.push({role: 'assistant', content: 'Welcome to TUGON AI Parish Assistant. How may I assist you today?'});
    input.focus();

    function resetInputHeight() {
        input.style.height = 'auto';
    }

    input.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });

    input.addEventListener('focus', function() {
        setTimeout(scrollLatest, 300);
    });

    form.addEventListener('submit', function(event) {
        event.preventDefault();
        const message = input.value.trim();
        if (!message) return;
        input.value = '';
        resetInputHeight();
        askAssistant(message, 'chat');
        input.focus();
    });

    input.addEventListener('keydown', function(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    document.addEventListener('click', function(event) {
        const chip = event.target.closest('[data-ai-prompt]');
        if (chip) {
            askAssistant(chip.getAttribute('data-ai-prompt'), 'chat');
            return;
        }

        const copyButton = event.target.closest('.ai-copy-btn');
        if (copyButton) {
            const bubble = copyButton.closest('.ai-bubble');
            navigator.clipboard.writeText(textFromHtml(bubble.innerHTML).replace(/\s*Copy\s*$/, '').trim()).then(function() {
                copyButton.textContent = 'Copied';
                setTimeout(function() { copyButton.textContent = 'Copy'; }, 1400);
            }).catch(function() {
                copyButton.textContent = 'Copy failed';
                setTimeout(function() { copyButton.textContent = 'Copy'; }, 1400);
            });
        }
    });

    clearBtn.addEventListener('click', function() {
        log.innerHTML = '';
        conversationHistory.length = 0;
        addMessage('assistant', {
            title: 'Conversation Cleared',
            body: 'Let me help you start fresh. You may ask about certificates, sacraments, schedules, announcements, or request status.',
            suggestions: ['Baptism Requirements', 'Mass Schedule', 'Track Request Status']
        });
        conversationHistory.push({role: 'assistant', content: 'Let me help you start fresh. You may ask about certificates, sacraments, schedules, announcements, or request status.'});
        input.focus();
    });

    minimizeBtn.addEventListener('click', function() {
        card.classList.toggle('is-minimized');
        minimizeBtn.innerHTML = card.classList.contains('is-minimized') ? '<i class="fas fa-up-right-and-down-left-from-center"></i>' : '<i class="fas fa-minus"></i>';
    });

    darkToggle.addEventListener('click', function() {
        page.classList.toggle('ai-dark-mode');
        darkToggle.innerHTML = page.classList.contains('ai-dark-mode') ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    });

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
        voiceBtn.disabled = true;
        voiceBtn.title = 'Voice input is not supported by this browser';
    } else {
        const recognizer = new SpeechRecognition();
        recognizer.lang = 'en-PH';
        recognizer.continuous = false;
        recognizer.interimResults = false;
        voiceBtn.addEventListener('click', function() {
            voiceBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';
            recognizer.start();
        });
        recognizer.onresult = function(event) {
            input.value = event.results[0][0].transcript || '';
        };
        recognizer.onend = function() {
            voiceBtn.innerHTML = '<i class="fas fa-microphone"></i>';
        };
    }
});
</script>

<?php include '../templates/footer.php'; ?>
