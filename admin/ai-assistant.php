<?php
/**
 * Admin AI Assistant Module - Provides staff-facing guidance and search support for parish administration.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('ai.use');

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
                    <div class="ai-chat-log" id="aiChatLog" aria-live="polite">
                        <div class="ai-message assistant">
                            <strong>AI Parish Assistant</strong>
                            <p>Ask for pending-request summaries, transaction guidance, parish inquiry responses, or smart search across records available to admins.</p>
                        </div>
                    </div>
                    <form class="ai-chat-form" id="aiChatForm">
                        <label class="visually-hidden" for="aiMessage">Ask the admin assistant</label>
                        <textarea id="aiMessage" rows="3" placeholder="Example: Summarize pending requests and search baptismal certificate items"></textarea>
                        <div class="ai-form-actions">
                            <button class="btn btn-outline-primary" type="button" data-ai-prompt="Show analytics report summary">Analytics</button>
                            <button class="btn btn-outline-primary" type="button" data-ai-prompt="Search pending certificate requests">Smart Search</button>
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

        // Escape Html Function - Documents this helper's role in the parish management workflow.
        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function(char) {
                return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'})[char];
            });
        }

        // Add Message Function - Documents this helper's role in the parish management workflow.
        function addMessage(type, html) {
            const item = document.createElement('div');
            item.className = 'ai-message ' + type;
            item.innerHTML = html;
            log.appendChild(item);
            log.scrollTop = log.scrollHeight;
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
            addMessage('user', '<strong>Admin</strong><p>' + escapeHtml(message) + '</p>');
            addMessage('assistant loading', '<strong>AI Parish Assistant</strong><p>Analyzing parish data...</p>');

            fetch('../api/ai-assistant.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({message: message, mode: mode || 'chat'})
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                const loading = log.querySelector('.ai-message.loading');
                if (loading) loading.remove();
                if (!data.success) {
                    addMessage('assistant', '<strong>AI Parish Assistant</strong><p>' + escapeHtml(data.message || 'Unable to answer right now.') + '</p>');
                    return;
                }
                const steps = data.guidance && data.guidance.steps
                    ? '<ol>' + data.guidance.steps.map(function(step) { return '<li>' + escapeHtml(step) + '</li>'; }).join('') + '</ol>'
                    : '';
                addMessage('assistant', '<strong>' + escapeHtml(data.guidance.title) + '</strong><p>' + escapeHtml(data.answer) + '</p>' + steps);
                renderResults(data.search_results);
                renderAnalytics(data.analytics);
            })
            .catch(function() {
                const loading = log.querySelector('.ai-message.loading');
                if (loading) loading.remove();
                addMessage('assistant', '<strong>AI Parish Assistant</strong><p>Unable to reach the assistant endpoint. Please try again.</p>');
            });
        }

        form.addEventListener('submit', function(event) {
            event.preventDefault();
            const message = input.value.trim();
            if (!message) return;
            input.value = '';
            askAssistant(message, 'chat');
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

        askAssistant('Show analytics report summary', 'analytics');
    });
    </script>
</body>
</html>
