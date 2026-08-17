<?php
/**
 * Admin AI Assistant Module - Provides staff-facing guidance and search support for parish administration.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();

header('Location: ' . BASE_URL . 'admin/dashboard.php');
exit;

$page_title = 'AI Assistant - Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/holy-theme.css">
    <link rel="stylesheet" href="../assets/css/premium-parish.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
</head>
<body class="church-theme">
    <div class="premium-admin-shell">
        <?php include '../includes/admin-sidebar.php'; ?>
        <main class="premium-admin-content">
            <section class="premium-admin-hero">
                <div>
                    <span class="premium-pill landing-eyebrow"><i class="fas fa-robot"></i> AI-powered operations</span>
                    <h1>AI Assistant for Parish Administration</h1>
                    <p>Use automated inquiry responses, transaction guidance, smart search, and AI-assisted reporting from one administrative workspace.</p>
                </div>
                <div class="hero-orb" aria-hidden="true">
                    <i class="fas fa-wand-magic-sparkles"></i>
                </div>
            </section>

            <section class="ai-workspace admin-ai-workspace">
                <div class="premium-panel premium-glass ai-chat-panel">
                    <div class="premium-panel-header">
                        <h2 class="premium-panel-title"><i class="fas fa-comments"></i> Ask TUGON AI</h2>
                    </div>
                    <div class="ai-chat-log" id="aiChatLog" aria-live="polite"></div>
                    <form class="ai-chat-form" id="aiChatForm">
                        <label class="visually-hidden" for="aiMessage">Ask the admin assistant</label>
                        <textarea id="aiMessage" rows="3" placeholder="Example: Summarize pending requests and search baptismal certificate items"></textarea>
                        <div class="ai-form-actions">
                            <button class="btn btn-outline-primary" type="button" data-ai-prompt="Show analytics report summary">Analytics</button>
                            <button class="btn btn-outline-primary" type="button" data-ai-prompt="Search pending certificate requests">Smart Search</button>
                            <button class="btn btn-outline-secondary" type="button" id="aiClearBtn"><i class="fas fa-trash-can"></i> Clear</button>
                            <button class="btn btn-primary" type="submit"><i class="fas fa-wand-magic-sparkles"></i> Ask Assistant</button>
                        </div>
                    </form>
                </div>

                <aside class="premium-panel premium-glass ai-support-panel">
                    <div class="premium-panel-header">
                        <h2 class="premium-panel-title"><i class="fas fa-magnifying-glass-chart"></i> Smart Retrieval</h2>
                    </div>
                    <div id="aiSearchResults" class="ai-results-empty">Search results will appear here.</div>

                    <hr>

                    <div class="premium-panel-header">
                        <h2 class="premium-panel-title"><i class="fas fa-chart-simple"></i> AI Reporting</h2>
                    </div>
                    <div id="aiAnalytics" class="ai-metric-list"></div>
                </aside>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('aiChatForm');
        const input = document.getElementById('aiMessage');
        const topSearch = document.getElementById('adminAiSearch');
        const log = document.getElementById('aiChatLog');
        const results = document.getElementById('aiSearchResults');
        const analytics = document.getElementById('aiAnalytics');
        const promptButtons = document.querySelectorAll('[data-ai-prompt]');
        const clearBtn = document.getElementById('aiClearBtn');
        const sendBtn = form.querySelector('button[type="submit"]');
        const conversationHistory = [];

        // Escape Html Function - Documents this helper's role in the parish management workflow.
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

        function typingDelayFor(text) {
            const length = String(text || '').length;
            return Math.min(1300, Math.max(430, length * 8));
        }

        function typeText(target, text, done) {
            const value = String(text || '');
            let index = 0;
            const speed = Math.max(10, Math.min(24, Math.floor(950 / Math.max(value.length, 1))));
            target.textContent = '';
            function tick() {
                target.textContent = value.slice(0, index);
                log.scrollTop = log.scrollHeight;
                index += 1;
                if (index <= value.length) {
                    window.setTimeout(tick, speed);
                } else if (typeof done === 'function') {
                    done();
                }
            }
            tick();
        }

        // Add Message Function - Documents this helper's role in the parish management workflow.
        function addMessage(type, options) {
            const title = options.title || (type === 'user' ? 'Admin' : 'AI Parish Assistant');
            const body = options.body || '';
            const steps = options.steps || [];
            const loading = options.loading || false;
            const stream = options.stream || false;
            const item = document.createElement('div');
            item.className = 'ai-message ' + type + (loading ? ' loading' : '');
            const stepsHtml = steps.length ? '<ol>' + steps.map(function(step) { return '<li>' + escapeHtml(step) + '</li>'; }).join('') + '</ol>' : '';
            const bodyHtml = loading
                ? '<div class="ai-typing-line">AI Parish Assistant is typing <span class="ai-typing-dots"><span></span><span></span><span></span></span></div>'
                : '<p><span class="ai-response-text">' + (stream ? '' : escapeHtml(body)) + '</span></p>' + stepsHtml;
            const copyHtml = type === 'assistant' && !loading ? '<button type="button" class="ai-copy-btn">Copy</button>' : '';
            item.innerHTML =
                '<strong>' + escapeHtml(title) + '</strong>' +
                bodyHtml +
                '<div class="ai-message-meta"><span>' + currentTime() + '</span>' + copyHtml + '</div>';
            log.appendChild(item);
            log.scrollTop = log.scrollHeight;
            if (stream) {
                typeText(item.querySelector('.ai-response-text'), body, function() {
                    input.focus();
                });
            }
            return item;
        }

        function removeLoading() {
            const loading = log.querySelector('.ai-message.loading');
            if (loading) loading.remove();
        }

        // Render Results Function - Documents this helper's role in the parish management workflow.
        function renderResults(items) {
            if (!items || !items.length) {
                results.className = 'ai-results-empty';
                results.innerHTML = 'No matching parish records found.';
                return;
            }
            results.className = 'ai-result-list';
            results.innerHTML = items.map(function(item) {
                return '<a class="ai-result-item" href="' + escapeHtml(item.url) + '">' +
                    '<span>' + escapeHtml(item.module) + '</span>' +
                    '<strong>' + escapeHtml(item.title) + '</strong>' +
                    '<small>' + escapeHtml(item.meta) + '</small>' +
                    '</a>';
            }).join('');
        }

        // Render Analytics Function - Documents this helper's role in the parish management workflow.
        function renderAnalytics(data) {
            if (!data || !data.metrics) return;
            const metrics = Object.keys(data.metrics).map(function(label) {
                return '<div class="ai-metric"><span>' + escapeHtml(label) + '</span><strong>' + escapeHtml(data.metrics[label]) + '</strong></div>';
            }).join('');
            const insights = (data.insights || []).map(function(text) {
                return '<li>' + escapeHtml(text) + '</li>';
            }).join('');
            analytics.innerHTML = metrics + '<ul class="ai-insight-list">' + insights + '</ul>';
        }

        // Ask Assistant Function - Documents this helper's role in the parish management workflow.
        function askAssistant(message, mode) {
            addMessage('user', {title: 'Admin', body: message});
            conversationHistory.push({role: 'user', content: message});
            addMessage('assistant', {loading: true});
            sendBtn.disabled = true;

            fetch('../api/ai-assistant.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({message: message, mode: mode || 'chat', conversation: conversationHistory.slice(-8)})
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                const answer = data.success ? (data.answer || 'I prepared a parish administration response.') : (data.message || 'Unable to answer right now.');
                const title = data.success && data.guidance && data.guidance.title ? data.guidance.title : 'AI Parish Assistant';
                window.setTimeout(function() {
                    removeLoading();
                    sendBtn.disabled = false;
                    addMessage('assistant', {
                        title: title,
                        body: answer,
                        steps: data.success && data.guidance && data.guidance.steps ? data.guidance.steps : [],
                        stream: true
                    });
                    conversationHistory.push({role: 'assistant', content: answer});
                    if (data.success) {
                        renderResults(data.search_results);
                        renderAnalytics(data.analytics);
                    }
                }, typingDelayFor(answer));
            })
            .catch(function() {
                const answer = 'Unable to reach the assistant endpoint. Please try again.';
                window.setTimeout(function() {
                    removeLoading();
                    sendBtn.disabled = false;
                    addMessage('assistant', {title: 'Connection Issue', body: answer, stream: true});
                }, typingDelayFor(answer));
            });
        }

        form.addEventListener('submit', function(event) {
            event.preventDefault();
            const message = input.value.trim();
            if (!message) return;
            input.value = '';
            askAssistant(message, 'chat');
            input.focus();
        });

        input.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });

        log.addEventListener('click', function(event) {
            const copyButton = event.target.closest('.ai-copy-btn');
            if (!copyButton) return;
            const bubble = copyButton.closest('.ai-message');
            navigator.clipboard.writeText(textFromHtml(bubble.innerHTML).replace(/\s*Copy\s*$/, '').trim()).then(function() {
                copyButton.textContent = 'Copied';
                setTimeout(function() { copyButton.textContent = 'Copy'; }, 1400);
            }).catch(function() {
                copyButton.textContent = 'Copy failed';
                setTimeout(function() { copyButton.textContent = 'Copy'; }, 1400);
            });
        });

        clearBtn.addEventListener('click', function() {
            log.innerHTML = '';
            conversationHistory.length = 0;
            addMessage('assistant', {
                title: 'AI Parish Assistant',
                body: 'Conversation cleared. Ask for pending-request summaries, transaction guidance, parish inquiry responses, analytics, or smart search across records available to admins.'
            });
            input.focus();
        });

        promptButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                askAssistant(button.getAttribute('data-ai-prompt'), 'search');
            });
        });

        if (topSearch) {
            topSearch.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    const message = topSearch.value.trim();
                    if (message) askAssistant(message, 'search');
                }
            });
        }

        addMessage('assistant', {
            title: 'AI Parish Assistant',
            body: 'Ask for pending-request summaries, transaction guidance, parish inquiry responses, or smart search across records available to admins.'
        });
        conversationHistory.push({role: 'assistant', content: 'Ask for pending-request summaries, transaction guidance, parish inquiry responses, or smart search across records available to admins.'});
        input.focus();
    });
    </script>
</body>
</html>
