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
        --ai-primary: #344536;
        --ai-primary-dark: #243326;
        --ai-primary-deep: #1B261D;
        --ai-gold: #C9A646;
        --ai-gold-hover: #B89332;
        --ai-gold-soft: rgba(201, 166, 70, 0.12);
        --ai-gold-border: #E7DFC9;
        --ai-bg-cream: #F8F5ED;
        --ai-bg-warm: #FAF7F0;
        --ai-surface: #FFFDF8;
        --ai-text: #30342F;
        --ai-muted: #7D8078;
        --ai-line: #E7DFC9;
        --ai-card: #FFFDF8;
        --ai-shadow: 0 16px 48px rgba(34, 45, 36, 0.14), 0 4px 16px rgba(201, 166, 70, 0.08);
    }

    .tugon-ai-page {
        min-height: calc(100vh - 90px);
        padding: clamp(18px, 3vw, 34px);
        background:
            radial-gradient(circle at top left, rgba(201, 166, 70, 0.1), transparent 32%),
            linear-gradient(135deg, #F8F5ED 0%, #FAF7F0 48%, #F8F5ED 100%);
    }

    .tugon-ai-shell {
        width: min(100%, 1180px);
        margin: 0 auto;
        display: grid;
        gap: 18px;
    }

    .tugon-ai-card {
        overflow: hidden;
        border: 1px solid var(--ai-line);
        border-radius: 20px;
        background: var(--ai-card);
        box-shadow: var(--ai-shadow);
        backdrop-filter: blur(18px);
    }

    .tugon-ai-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 20px clamp(18px, 3vw, 28px);
        color: #FFFDF8;
        background:
            linear-gradient(135deg, #344536 0%, #243326 100%);
        border-bottom: 2px solid var(--ai-gold);
    }

    .tugon-ai-identity {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 0;
    }

    .tugon-ai-avatar {
        width: 54px;
        height: 54px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        flex: 0 0 auto;
        color: #FFFDF8;
        background: linear-gradient(135deg, rgba(201, 166, 70, 0.3) 0%, rgba(201, 166, 70, 0.12) 100%);
        border: 1.5px solid var(--ai-gold);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2), 0 0 10px rgba(201, 166, 70, 0.3);
        font-size: 1.4rem;
    }

    .tugon-ai-title-block h1 {
        margin: 0;
        color: #FFFDF8;
        font-family: "Playfair Display", "Cinzel", Georgia, serif;
        font-size: clamp(1.35rem, 2.5vw, 1.9rem);
        font-weight: 700;
        letter-spacing: 0.3px;
    }

    .tugon-ai-title-block p {
        margin: 3px 0 0;
        color: #D8CEB8;
        font-size: 0.9rem;
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
        border: 1px solid rgba(201, 166, 70, 0.4);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.08);
        color: #FFFDF8;
        min-height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 8px 14px;
        font-weight: 600;
        font-size: 0.82rem;
    }

    .ai-status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #22C55E;
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.3);
    }

    .ai-icon-btn {
        width: 36px;
        padding: 0;
        cursor: pointer;
        transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease;
    }

    .ai-icon-btn:hover {
        background: rgba(201, 166, 70, 0.25);
        border-color: var(--ai-gold);
        transform: translateY(-1px);
        color: #FFFFFF;
    }

    .tugon-ai-body {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 290px;
        gap: 18px;
        padding: clamp(16px, 2.4vw, 24px);
        background: #FAF7F0;
    }

    .tugon-ai-main {
        min-width: 0;
        display: grid;
        grid-template-rows: auto minmax(430px, 58vh) auto;
        gap: 14px;
    }

    .tugon-ai-welcome {
        border: 1px solid var(--ai-line);
        border-left: 4px solid var(--ai-gold);
        border-radius: 16px;
        padding: 16px 20px;
        background: #FFFDF8;
        box-shadow: 0 3px 12px rgba(52, 69, 54, 0.04);
    }

    .tugon-ai-welcome h2 {
        margin: 0 0 6px;
        color: var(--ai-primary);
        font-family: "Playfair Display", Georgia, serif;
        font-size: 1.18rem;
        font-weight: 700;
    }

    .tugon-ai-welcome p {
        margin: 0;
        color: var(--ai-text);
        font-size: 0.9rem;
        line-height: 1.55;
    }

    .ai-quick-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .ai-chip {
        border: 1px solid var(--ai-line);
        border-radius: 999px;
        background: #FFFDF8;
        color: var(--ai-primary);
        min-height: 36px;
        padding: 8px 14px;
        font-weight: 600;
        font-size: 0.82rem;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        cursor: pointer;
        transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease, color 0.16s ease, box-shadow 0.16s ease;
        box-shadow: 0 1px 4px rgba(52, 69, 54, 0.04);
    }

    .ai-chip:hover {
        background: var(--ai-primary);
        border-color: var(--ai-primary);
        color: #FFFFFF;
        box-shadow: 0 4px 12px rgba(52, 69, 54, 0.15);
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
        background: #FFFDF8;
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
        border-radius: 12px;
        display: grid;
        place-items: center;
        color: #FFFDF8;
        background: var(--ai-primary);
        border: 1px solid var(--ai-gold);
        box-shadow: 0 4px 12px rgba(52, 69, 54, 0.18);
        font-size: 0.95rem;
    }

    .ai-message.user .ai-message-avatar {
        grid-column: 2;
        background: #4A4E48;
        border-color: #7D8078;
    }

    .ai-bubble {
        width: fit-content;
        max-width: min(720px, 100%);
        padding: 13px 16px;
        border-radius: 18px 18px 18px 6px;
        color: var(--ai-text);
        background: #FFFDF8;
        border: 1px solid var(--ai-line);
        border-left: 3px solid var(--ai-gold);
        box-shadow: 0 3px 14px rgba(52, 69, 54, 0.05);
    }

    .ai-message.user .ai-bubble {
        justify-self: end;
        grid-column: 1;
        grid-row: 1;
        color: #FFFFFF;
        background: linear-gradient(135deg, var(--ai-primary), #243326);
        border: 1px solid rgba(201, 166, 70, 0.35);
        border-radius: 18px 18px 6px 18px;
        box-shadow: 0 3px 12px rgba(52, 69, 54, 0.15);
    }

    .ai-bubble strong {
        display: block;
        margin-bottom: 5px;
        font-weight: 700;
        color: var(--ai-primary);
        font-family: "Playfair Display", Georgia, serif;
    }

    .ai-message.user .ai-bubble strong {
        color: #FFFDF8;
    }

    .ai-bubble p {
        margin: 0;
        line-height: 1.6;
        color: inherit;
    }

    .ai-bubble ol,
    .ai-bubble ul {
        margin: 8px 0 0;
        padding-left: 18px;
    }

    .ai-bubble li {
        margin-bottom: 4px;
        line-height: 1.5;
    }

    .ai-meta {
        display: flex;
        align-items: center;
        gap: 9px;
        margin-top: 8px;
        color: var(--ai-muted);
        font-size: 0.76rem;
        font-weight: 600;
    }

    .ai-message.user .ai-meta {
        color: rgba(255, 255, 255, 0.8);
        justify-content: flex-end;
    }

    .ai-copy-btn {
        border: 0;
        padding: 2px 6px;
        border-radius: 4px;
        background: transparent;
        color: var(--ai-muted);
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .ai-copy-btn:hover {
        color: var(--ai-gold);
        background: var(--ai-gold-soft);
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
        font-weight: 700;
    }

    .ai-typing-line {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        color: var(--ai-muted);
        font-weight: 600;
    }

    .ai-typing-dots {
        display: inline-flex;
        gap: 5px;
    }

    .ai-typing-dots span {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--ai-gold);
        animation: aiTyping 1.2s infinite ease-in-out;
    }

    .ai-typing-dots span:nth-child(2) {
        animation-delay: 0.16s;
    }

    .ai-typing-dots span:nth-child(3) {
        animation-delay: 0.32s;
    }

    .tugon-ai-form {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        align-items: end;
        padding: 12px 14px;
        border: 1.5px solid var(--ai-line);
        border-radius: 18px;
        background: #FFFDF8;
        box-shadow: 0 4px 18px rgba(52, 69, 54, 0.05);
        transition: border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .tugon-ai-form:focus-within {
        border-color: var(--ai-gold);
        box-shadow: 0 0 0 3.5px rgba(201, 166, 70, 0.18);
    }

    .tugon-ai-form textarea {
        min-height: 46px;
        max-height: 130px;
        resize: vertical;
        border: 0;
        outline: 0;
        padding: 10px 8px;
        color: var(--ai-text);
        background: transparent;
        font-family: inherit;
        font-size: 0.92rem;
    }

    .ai-send-btn {
        border: 0;
        border-radius: 12px;
        min-height: 44px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        font-weight: 700;
        cursor: pointer;
        transition: transform 0.16s ease, background 0.16s ease, box-shadow 0.16s ease;
        padding: 0 20px;
        color: #FFFDF8;
        background: linear-gradient(135deg, var(--ai-primary), #243326);
        border: 1px solid var(--ai-gold);
        box-shadow: 0 4px 14px rgba(52, 69, 54, 0.2);
    }

    .ai-send-btn:hover {
        background: linear-gradient(135deg, var(--ai-gold), var(--ai-gold-hover));
        color: #FFFFFF;
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(201, 166, 70, 0.35);
    }

    .tugon-ai-side {
        display: grid;
        align-content: start;
        gap: 14px;
    }

    .ai-side-panel {
        border: 1px solid var(--ai-line);
        border-radius: 18px;
        padding: 16px 18px;
        background: #FFFDF8;
        box-shadow: 0 4px 16px rgba(52, 69, 54, 0.04);
    }

    .ai-side-panel h3 {
        margin: 0 0 10px;
        color: var(--ai-primary);
        font-size: 0.95rem;
        font-weight: 700;
        font-family: "Playfair Display", Georgia, serif;
    }

    .ai-scope-list {
        display: grid;
        gap: 8px;
        margin: 0;
        padding: 0;
        list-style: none;
        color: #4A4E48;
        font-weight: 500;
        font-size: 0.86rem;
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
        --ai-text: #E5E2DA;
        --ai-muted: #9E9F9A;
        --ai-line: rgba(201, 166, 70, 0.25);
        --ai-card: #202D22;
        background:
            radial-gradient(circle at top left, rgba(201, 166, 70, 0.1), transparent 28%),
            linear-gradient(135deg, #182319, #202D22);
    }

    .ai-dark-mode .tugon-ai-card,
    .ai-dark-mode .tugon-ai-welcome,
    .ai-dark-mode .tugon-chat-log,
    .ai-dark-mode .tugon-ai-form,
    .ai-dark-mode .ai-side-panel,
    .ai-dark-mode .ai-bubble {
        background: #202D22;
        color: var(--ai-text);
    }

    .ai-dark-mode .ai-chip {
        background: #28372A;
        color: #E8D8B5;
        border-color: rgba(201, 166, 70, 0.3);
    }

    .ai-dark-mode .tugon-ai-form textarea,
    .ai-dark-mode .ai-side-panel h3,
    .ai-dark-mode .tugon-ai-welcome h2 {
        color: #FFFDF8;
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
});
</script>

<?php include '../templates/footer.php'; ?>
