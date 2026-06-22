<?php
/**
 * Footer Template - Closes shared layouts and loads common scripts for user and admin pages.
 */
?>
    <?php if (isset($is_user_area) && $is_user_area): ?>
            </main>
            <footer class="user-footer">
                <span>&copy; 2026 San Lorenzo Ruiz Mission Station</span>
                <span><?php echo e(t('footer.tagline', 'Serving with faith and care')); ?></span>
            </footer>
        </div>
    </div>
    <?php elseif (isset($is_admin_area) && $is_admin_area): ?>
        </main>
    </div>
    <?php else: ?>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white py-4 mt-5">
        <div class="container text-center">
            <p>&copy; 2026 San Lorenzo Ruiz Mission Station. <?php echo e(t('footer.rights', 'All rights reserved.')); ?></p>
            <p><small><?php echo e(t('footer.powered', 'Powered by AI for efficient parish services')); ?></small></p>
        </div>
    </footer>
    <?php endif; ?>

    <div class="language-switcher floating-language" aria-label="<?php echo e(t('lang.label', 'Language')); ?>">
        <span><?php echo e(t('lang.label', 'Language')); ?></span>
        <a href="<?php echo e(tugonLanguageUrl('en')); ?>" class="<?php echo tugonCurrentLanguage() === 'en' ? 'active' : ''; ?>">EN</a>
        <a href="<?php echo e(tugonLanguageUrl('fil')); ?>" class="<?php echo tugonCurrentLanguage() === 'fil' ? 'active' : ''; ?>">FIL</a>
    </div>

    <?php if (isLoggedIn()): ?>
    <div class="ai-assistant-widget" id="aiAssistantWidget">
        <button class="ai-assistant-trigger" type="button" id="aiAssistantTrigger" aria-label="<?php echo e(t('chatbot.trigger_label', 'AI Parish Assistant')); ?>" aria-expanded="false">
            <span class="ai-assistant-glow" aria-hidden="true"></span>
            <span class="ai-assistant-icon" aria-hidden="true">
                <i class="fas fa-robot"></i>
                <i class="fas fa-wand-magic-sparkles"></i>
            </span>
        </button>
        <div class="ai-assistant-tooltip" role="tooltip"><?php echo e(t('chatbot.title', 'TUGON AI Parish Assistant')); ?></div>
        <section class="ai-assistant-panel" id="aiAssistantPanel" aria-hidden="true">
            <div class="ai-assistant-panel-header">
                <div class="ai-assistant-panel-mark" aria-hidden="true">
                    <i class="fas fa-church"></i>
                </div>
                <div>
                    <strong><?php echo e(t('chatbot.title', 'TUGON AI Parish Assistant')); ?></strong>
                    <span><?php echo e(t('chatbot.subtitle', 'Your Digital Parish Companion')); ?></span>
                </div>
                <div class="ai-assistant-status" id="aiAssistantStatus"><span></span> Online</div>
                <button class="ai-assistant-tool" type="button" id="aiAssistantClear" aria-label="Clear conversation" title="Clear conversation">
                    <i class="fas fa-trash-can"></i>
                </button>
                <button class="ai-assistant-tool" type="button" id="aiAssistantMinimize" aria-label="Minimize chat" title="Minimize chat">
                    <i class="fas fa-minus"></i>
                </button>
                <button class="ai-assistant-close" type="button" id="aiAssistantClose" aria-label="<?php echo e(t('chatbot.close_label', 'Close AI assistant')); ?>">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ai-assistant-panel-body">
                <div class="ai-assistant-live-answer" id="aiAssistantLiveAnswer" hidden>
                    <div class="ai-assistant-empty-state" id="aiAssistantEmptyState">
                        <i class="fas fa-church"></i>
                        <strong>Welcome to TUGON AI Parish Assistant</strong>
                        <span>I can help you with certificates, Mass schedules, parish events, sacramental requirements, announcements, FAQs, and request tracking.</span>
                    </div>
                </div>
                <div class="ai-assistant-quick" id="aiAssistantQuickActions" aria-label="Quick assistant actions"></div>

                <form class="ai-assistant-live-form" id="aiAssistantLiveForm">
                    <label class="ai-assistant-search" for="aiAssistantLiveInput">
                        <i class="fas fa-message" aria-hidden="true"></i>
                        <input type="text" id="aiAssistantLiveInput" data-no-autocomplete="true" placeholder="<?php echo e(t('chatbot.placeholder', 'Ask about certificates, status, schedule...')); ?>">
                    </label>
                    <button type="submit"><i class="fas fa-paper-plane"></i> <?php echo e(t('chatbot.send', 'Send')); ?></button>
                </form>
            </div>
        </section>
    </div>
    <?php endif; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Custom JS -->
    <script src="../assets/js/main.js"></script>
    <?php if (isLoggedIn()): ?>
    <script>
        (function() {
            const widget = document.getElementById('aiAssistantWidget');
            const trigger = document.getElementById('aiAssistantTrigger');
            const panel = document.getElementById('aiAssistantPanel');
            const close = document.getElementById('aiAssistantClose');
            const clear = document.getElementById('aiAssistantClear');
            const minimize = document.getElementById('aiAssistantMinimize');
            const status = document.getElementById('aiAssistantStatus');
            const liveForm = document.getElementById('aiAssistantLiveForm');
            const liveInput = document.getElementById('aiAssistantLiveInput');
            const liveAnswer = document.getElementById('aiAssistantLiveAnswer');
            const quickActions = document.getElementById('aiAssistantQuickActions');
            const chatLabels = {
                title: <?php echo json_encode(t('chatbot.title', 'TUGON AI Parish Assistant')); ?>,
                you: <?php echo json_encode(t('chatbot.you', 'You')); ?>,
                typing: <?php echo json_encode(t('chatbot.typing', 'TUGON AI is typing')); ?>,
                unable: <?php echo json_encode(t('chatbot.unable', 'Unable to answer right now.')); ?>,
                noAnswer: <?php echo json_encode(t('chatbot.no_answer', 'I could not find a Tugon answer for that question.')); ?>,
                endpointError: <?php echo json_encode(t('chatbot.endpoint_error', 'Unable to reach the chatbot endpoint. Please try again.')); ?>
            };
            const assistantActions = [
                {label: 'Baptism Requirements', prompt: 'What are the Baptism requirements?', icon: 'fa-water'},
                {label: 'Marriage Requirements', prompt: 'What are the Marriage requirements?', icon: 'fa-ring'},
                {label: 'Confirmation Requirements', prompt: 'What are the Confirmation requirements?', icon: 'fa-dove'},
                {label: 'Mass Schedule', prompt: 'What is the Mass schedule?', icon: 'fa-calendar-days'},
                {label: 'Request Certificate', prompt: 'How do I request a certificate?', icon: 'fa-file-lines'},
                {label: 'Track Request Status', prompt: 'How can I track my request status?', icon: 'fa-clipboard-check'},
                {label: 'Parish Announcements', prompt: 'Where can I view parish announcements?', icon: 'fa-bullhorn'},
                {label: 'Contact Parish Office', prompt: 'What are the parish office hours and contact guidance?', icon: 'fa-phone'}
            ];

            if (!widget || !trigger || !panel || !close) {
                return;
            }

            // Set Assistant Open Function - Documents this helper's role in the parish management workflow.
            function setAssistantOpen(isOpen) {
                widget.classList.toggle('is-open', isOpen);
                trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            }

            // Escape Html Function - Documents this helper's role in the parish management workflow.
            function escapeHtml(value) {
                return String(value).replace(/[&<>"']/g, function(char) {
                    return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'})[char];
                });
            }

            function currentTime() {
                return new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
            }

            function setTyping(isTyping) {
                if (!status) {
                    return;
                }
                status.innerHTML = isTyping ? '<span></span> TUGON AI is typing...' : '<span></span> Online';
            }

            function removeEmptyState() {
                const emptyState = document.getElementById('aiAssistantEmptyState');
                if (emptyState) {
                    emptyState.remove();
                }
            }

            function renderQuickActions() {
                if (!quickActions) {
                    return;
                }
                quickActions.innerHTML = assistantActions.map(function(action) {
                    return '<button type="button" data-ai-prompt="' + escapeHtml(action.prompt) + '"><i class="fas ' + action.icon + '"></i>' + escapeHtml(action.label) + '</button>';
                }).join('');
            }

            function relatedSuggestions(message) {
                const q = String(message || '').toLowerCase();
                if (q.indexOf('baptism') !== -1) return ['Marriage Requirements', 'Confirmation Requirements', 'Request Certificate'];
                if (q.indexOf('marriage') !== -1 || q.indexOf('wedding') !== -1) return ['Baptism Requirements', 'Mass Schedule', 'Contact Parish Office'];
                if (q.indexOf('confirmation') !== -1) return ['Baptism Requirements', 'Request Certificate', 'Parish Announcements'];
                if (q.indexOf('status') !== -1 || q.indexOf('track') !== -1) return ['Track Request Status', 'Notifications', 'Contact Parish Office'];
                if (q.indexOf('announcement') !== -1) return ['Parish Announcements', 'Mass Schedule', 'Parish Events'];
                return ['Baptism Requirements', 'Mass Schedule', 'Request Certificate'];
            }

            function suggestionHtml(message) {
                return '<div class="ai-assistant-suggestions"><span>You may also need:</span>' + relatedSuggestions(message).map(function(label) {
                    const action = assistantActions.find(function(item) { return item.label === label; }) || {label: label, prompt: label, icon: 'fa-circle-question'};
                    return '<button type="button" data-ai-prompt="' + escapeHtml(action.prompt) + '"><i class="fas ' + action.icon + '"></i>' + escapeHtml(action.label) + '</button>';
                }).join('') + '</div>';
            }

            // Append Chat Message Function - Documents this helper's role in the parish management workflow.
            function appendChatMessage(type, title, message, sourcePrompt) {
                if (!liveAnswer) {
                    return null;
                }
                liveAnswer.hidden = false;
                removeEmptyState();
                const item = document.createElement('div');
                item.className = 'ai-assistant-chat-message ' + type;
                const suggestions = type === 'assistant' ? suggestionHtml(sourcePrompt || message) : '';
                item.innerHTML = '<strong>' + escapeHtml(title) + '</strong><p>' + escapeHtml(message) + '</p>' + suggestions + '<div class="ai-assistant-message-meta"><span>' + currentTime() + '</span><button type="button" class="ai-assistant-copy">Copy</button></div>';
                liveAnswer.appendChild(item);
                liveAnswer.scrollTop = liveAnswer.scrollHeight;
                return item;
            }

            // Append Typing Bubble Function - Documents this helper's role in the parish management workflow.
            function appendTypingBubble() {
                if (!liveAnswer) {
                    return null;
                }
                liveAnswer.hidden = false;
                removeEmptyState();
                const item = document.createElement('div');
                item.className = 'ai-assistant-chat-message assistant loading';
                item.innerHTML = '<strong>' + escapeHtml(chatLabels.title) + '</strong><div class="ai-assistant-typing-line"><span>TUGON AI is typing</span><div class="ai-typing-dots" aria-label="' + escapeHtml(chatLabels.typing) + '"><span></span><span></span><span></span></div></div>';
                liveAnswer.appendChild(item);
                liveAnswer.scrollTop = liveAnswer.scrollHeight;
                return item;
            }

            // Ask Live Assistant Function - Documents this helper's role in the parish management workflow.
            function askLiveAssistant(message) {
                if (!liveAnswer) {
                    return;
                }
                appendChatMessage('user', chatLabels.you, message);
                const loading = appendTypingBubble();
                setTyping(true);

                fetch('<?php echo BASE_URL; ?>api/ai-assistant.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({message: message, mode: 'chat'})
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (loading) {
                        loading.remove();
                    }
                    setTyping(false);
                    if (!data.success) {
                        appendChatMessage('assistant', chatLabels.title, data.message || chatLabels.unable, message);
                        return;
                    }
                    appendChatMessage('assistant', data.guidance.title || chatLabels.title, data.answer || chatLabels.noAnswer, message);
                })
                .catch(function() {
                    if (loading) {
                        loading.remove();
                    }
                    setTyping(false);
                    appendChatMessage('assistant', chatLabels.title, chatLabels.endpointError, message);
                });
            }

            renderQuickActions();

            trigger.addEventListener('click', function() {
                setAssistantOpen(!widget.classList.contains('is-open'));
            });

            close.addEventListener('click', function() {
                setAssistantOpen(false);
            });

            if (clear && liveAnswer) {
                clear.addEventListener('click', function() {
                    liveAnswer.hidden = false;
                    liveAnswer.innerHTML = '<div class="ai-assistant-empty-state" id="aiAssistantEmptyState"><i class="fas fa-church"></i><strong>Conversation cleared</strong><span>Let me help you start fresh. Ask about certificates, sacraments, schedules, announcements, or request status.</span></div>';
                });
            }

            if (minimize) {
                minimize.addEventListener('click', function() {
                    panel.classList.toggle('is-minimized');
                    minimize.innerHTML = panel.classList.contains('is-minimized') ? '<i class="fas fa-up-right-and-down-left-from-center"></i>' : '<i class="fas fa-minus"></i>';
                });
            }

            if (liveForm && liveInput) {
                liveForm.addEventListener('submit', function(event) {
                    event.preventDefault();
                    const message = liveInput.value.trim();
                    if (!message) {
                        return;
                    }
                    liveInput.value = '';
                    askLiveAssistant(message);
                });

                liveInput.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        liveForm.requestSubmit();
                    }
                });
            }

            document.addEventListener('click', function(event) {
                const promptButton = event.target.closest('[data-ai-prompt]');
                if (promptButton && widget.contains(promptButton)) {
                    askLiveAssistant(promptButton.getAttribute('data-ai-prompt'));
                    return;
                }

                const copyButton = event.target.closest('.ai-assistant-copy');
                if (copyButton && widget.contains(copyButton)) {
                    const bubble = copyButton.closest('.ai-assistant-chat-message');
                    navigator.clipboard.writeText((bubble.innerText || '').replace(/\s*Copy\s*$/, '').trim()).then(function() {
                        copyButton.textContent = 'Copied';
                        setTimeout(function() { copyButton.textContent = 'Copy'; }, 1200);
                    });
                }
            });

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    setAssistantOpen(false);
                }
            });
        })();
    </script>
    <?php endif; ?>
    <script>
        (function() {
            const toggle = document.getElementById('darkModeToggle') || document.getElementById('adminThemeToggle');
            if (!toggle) {
                return;
            }
            const prefersDark = localStorage.getItem('theme') === 'dark' || localStorage.getItem('parishTheme') === 'dark';
            if (prefersDark) {
                document.body.setAttribute('data-theme', 'dark');
            }
            toggle.addEventListener('click', function() {
                const isDark = document.body.getAttribute('data-theme') === 'dark';
                if (isDark) {
                    document.body.removeAttribute('data-theme');
                    localStorage.setItem('theme', 'light');
                    localStorage.setItem('parishTheme', 'light');
                } else {
                    document.body.setAttribute('data-theme', 'dark');
                    localStorage.setItem('theme', 'dark');
                    localStorage.setItem('parishTheme', 'dark');
                }
            });
        })();
    </script>
</body>
</html>
