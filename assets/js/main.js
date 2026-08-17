/**
 * Main Frontend Script - Initializes shared UI behaviors, tooltips, notifications, search, and form helpers.
 */

// San Lorenzo Ruiz Mission Station - Main JavaScript

document.addEventListener('DOMContentLoaded', function() {
    
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize popovers
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Add active class to current navigation link
    const currentLocation = location.pathname;
    const menuItems = document.querySelectorAll('.nav-link');
    menuItems.forEach(function(item) {
        if(item.getAttribute('href') === currentLocation) {
            item.classList.add('active');
        }
    });

    // Animated counters for analytics cards
    const counters = document.querySelectorAll('[data-count]');
    counters.forEach(function(counter) {
        const target = parseInt(counter.getAttribute('data-count')) || 0;
        let current = 0;
        const duration = 900;
        const stepTime = Math.max(Math.floor(duration / (target || 1)), 12);
        const timer = setInterval(function() {
            current += Math.max(1, Math.floor(target / (duration / stepTime)));
            if (current >= target) {
                counter.textContent = target;
                clearInterval(timer);
            } else {
                counter.textContent = current;
            }
        }, stepTime);
    });

    initGlobalSearchAutocomplete();
    suppressSavedInfoOnRequestInputs();
    initParishNotifications();
    initStableDetailModals();

});

// Stable parishioner detail dialogs avoid competing Bootstrap modal handlers.
function initStableDetailModals() {
    const triggers = document.querySelectorAll('[data-stable-modal-open]');
    if (!triggers.length) return;

    // Keep fixed dialogs outside transformed/offset admin layout containers so
    // they are centered against the full viewport rather than the content pane.
    document.querySelectorAll('.stable-detail-modal').forEach(function(modal) {
        if (modal.parentElement !== document.body) document.body.appendChild(modal);
    });

    let activeModal = null;
    let returnFocus = null;

    function closeModal(modal) {
        if (!modal || !modal.classList.contains('is-open')) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('stable-modal-open');
        activeModal = null;
        if (returnFocus && document.contains(returnFocus)) returnFocus.focus();
        returnFocus = null;
    }

    function openModal(modal, trigger) {
        if (!modal) return;
        if (activeModal && activeModal !== modal) closeModal(activeModal);
        returnFocus = trigger || document.activeElement;
        activeModal = modal;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('stable-modal-open');
        const closeButton = modal.querySelector('[data-stable-modal-close]');
        if (closeButton) closeButton.focus({preventScroll: true});
    }

    triggers.forEach(function(trigger) {
        trigger.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            const selector = trigger.getAttribute('data-stable-modal-open');
            if (!selector || selector.charAt(0) !== '#') return;
            openModal(document.querySelector(selector), trigger);
        });
    });

    document.addEventListener('click', function(event) {
        const closeButton = event.target.closest('[data-stable-modal-close]');
        if (closeButton) {
            event.preventDefault();
            closeModal(closeButton.closest('.stable-detail-modal'));
            return;
        }
        if (activeModal && event.target === activeModal) closeModal(activeModal);
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && activeModal) closeModal(activeModal);
    });
}

// AJAX function to load content
function loadContent(url, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    container.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    
    fetch(url)
        .then(response => response.text())
        .then(data => {
            container.innerHTML = data;
        })
        .catch(error => {
            container.innerHTML = '<div class="alert alert-danger">Error loading content</div>';
            console.error('Error:', error);
        });
}

// Format date helper
function formatDate(dateString) {
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return new Date(dateString).toLocaleDateString('en-US', options);
}

// Show notification
function showNotification(message, type = 'info', title = '') {
    const manager = window.ParishNotify;
    if (manager && typeof manager.show === 'function') {
        manager.show({ message: message, type: type, title: title });
    }
}

// Confirm delete
function confirmDelete(message = 'Are you sure you want to delete this item?') {
    return confirm(message);
}

// Init Parish Notifications Function - Creates a reusable global toast/snackbar service.
function initParishNotifications() {
    if (window.ParishNotify && window.ParishNotify.ready) {
        return;
    }

    const typeMap = {
        success: { icon: 'fa-circle-check', title: 'Success' },
        error: { icon: 'fa-circle-xmark', title: 'Error' },
        warning: { icon: 'fa-triangle-exclamation', title: 'Warning' },
        info: { icon: 'fa-circle-info', title: 'Information' }
    };
    const aliases = {
        danger: 'error',
        failed: 'error',
        failure: 'error',
        ok: 'success',
        notice: 'info',
        primary: 'info',
        secondary: 'info'
    };

    function normalizeType(type) {
        type = String(type || 'info').toLowerCase();
        type = aliases[type] || type;
        return typeMap[type] ? type : 'info';
    }

    function ensureContainer() {
        let container = document.getElementById('parishToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.className = 'parish-toast-container';
            container.id = 'parishToastContainer';
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-atomic', 'true');
            document.body.appendChild(container);
        }
        return container;
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function(char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function show(options, legacyType, legacyTitle) {
        if (typeof options === 'string') {
            options = { message: options, type: legacyType, title: legacyTitle };
        }

        const message = String((options && options.message) || '').trim();
        if (!message) {
            return null;
        }

        const type = normalizeType(options.type);
        const meta = typeMap[type];
        const title = String(options.title || meta.title);
        const duration = Math.max(3000, Math.min(parseInt(options.duration, 10) || 4200, 7000));
        const container = ensureContainer();
        const toast = document.createElement('div');
        toast.className = 'parish-toast parish-toast-' + type;
        toast.setAttribute('role', type === 'success' || type === 'info' ? 'status' : 'alert');
        toast.innerHTML = [
            '<div class="parish-toast-icon"><i class="fas ' + meta.icon + '"></i></div>',
            '<div class="parish-toast-body">',
            '<strong>' + escapeHtml(title) + '</strong>',
            '<span>' + escapeHtml(message) + '</span>',
            '</div>',
            '<button type="button" class="parish-toast-close" aria-label="Close notification"><i class="fas fa-xmark"></i></button>',
            '<div class="parish-toast-bar" style="animation-duration: ' + duration + 'ms"></div>'
        ].join('');

        function close() {
            if (toast.classList.contains('is-leaving')) {
                return;
            }
            toast.classList.add('is-leaving');
            window.setTimeout(function() {
                toast.remove();
            }, 260);
        }

        toast.querySelector('.parish-toast-close').addEventListener('click', close);
        container.appendChild(toast);
        window.requestAnimationFrame(function() {
            toast.classList.add('is-visible');
        });
        window.setTimeout(close, duration);
        return toast;
    }

    function typeFromAlert(alert) {
        if (alert.classList.contains('alert-success')) return 'success';
        if (alert.classList.contains('alert-danger')) return 'error';
        if (alert.classList.contains('alert-warning')) return 'warning';
        if (alert.classList.contains('alert-info') || alert.classList.contains('alert-primary')) return 'info';
        return '';
    }

    function promoteLegacyAlerts() {
        document.querySelectorAll('.alert:not([data-no-toast])').forEach(function(alert) {
            const type = typeFromAlert(alert);
            const text = alert.textContent.replace(/\s+/g, ' ').trim();
            if (type && text) {
                show({ type: type, message: text });
                alert.dataset.noToast = 'true';
            }
        });
    }

    function notifyFromJson(data, method) {
        if (!data || typeof data !== 'object') {
            return;
        }

        const message = data.message || data.error || data.notice;
        const hasActionShape = Object.prototype.hasOwnProperty.call(data, 'status')
            || Object.prototype.hasOwnProperty.call(data, 'type')
            || Object.prototype.hasOwnProperty.call(data, 'success')
            || Object.prototype.hasOwnProperty.call(data, 'ok');

        if (!message || !hasActionShape) {
            return;
        }

        const shouldNotify = data.notify !== false && (method !== 'GET' || data.notify === true);
        if (!shouldNotify) {
            return;
        }

        let type = data.type || data.status;
        if (!type) {
            type = (data.success === true || data.ok === true) ? 'success' : 'error';
        }
        show({ type: type, message: message, title: data.title || '' });
    }

    const nativeConfirm = window.confirm.bind(window);
    window.confirm = function(message) {
        show({ type: 'warning', message: message || 'This action needs your confirmation.', title: 'Please Confirm', duration: 3500 });
        return nativeConfirm(message);
    };

    if (window.fetch && !window.fetch.__parishNotificationsWrapped) {
        const nativeFetch = window.fetch.bind(window);
        const wrappedFetch = function(input, init) {
            const method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
            return nativeFetch(input, init).then(function(response) {
                const contentType = response.headers ? (response.headers.get('content-type') || '') : '';
                if (contentType.indexOf('application/json') !== -1) {
                    response.clone().json().then(function(data) {
                        notifyFromJson(data, method);
                    }).catch(function() {});
                }
                return response;
            }).catch(function(error) {
                show({ type: 'error', message: 'Network connection lost. Please try again.' });
                throw error;
            });
        };
        wrappedFetch.__parishNotificationsWrapped = true;
        window.fetch = wrappedFetch;
    }

    window.ParishNotify = {
        ready: true,
        show: show,
        success: function(message, title) { return show({ type: 'success', message: message, title: title }); },
        error: function(message, title) { return show({ type: 'error', message: message, title: title }); },
        warning: function(message, title) { return show({ type: 'warning', message: message, title: title }); },
        info: function(message, title) { return show({ type: 'info', message: message, title: title }); }
    };

    (window.parishInitialNotifications || []).forEach(function(item) {
        show(item);
    });
    promoteLegacyAlerts();
}

// Export to CSV
function exportTableToCSV(filename = 'export.csv') {
    const table = document.querySelector('table');
    if (!table) return;
    
    let csv = [];
    table.querySelectorAll('tr').forEach(row => {
        let csvRow = [];
        row.querySelectorAll('td, th').forEach(cell => {
            csvRow.push('"' + cell.textContent.trim() + '"');
        });
        csv.push(csvRow.join(','));
    });
    
    downloadCSV(csv.join('\n'), filename);
}

// Download CSV
function downloadCSV(csv, filename) {
    const csvFile = new Blob([csv], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.href = URL.createObjectURL(csvFile);
    downloadLink.download = filename;
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

// Print page
function printPage() {
    window.print();
}

// Search functionality
function searchTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    
    if (!input || !table) return;
    
    input.addEventListener('keyup', function() {
        const searchTerm = input.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    });
}

// Form validation
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return false;
    
    return form.checkValidity() === false ? false : true;
}

// AJAX form submission
function submitFormAjax(formId, successMessage = 'Success!') {
    const form = document.getElementById(formId);
    if (!form) return;
    
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(form);
        const url = form.getAttribute('action');
        const method = form.getAttribute('method') || 'POST';
        
        fetch(url, {
            method: method,
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(successMessage, 'success');
                form.reset();
            } else {
                showNotification(data.message || 'An error occurred', 'danger');
            }
        })
        .catch(error => {
            showNotification('Error: ' + error, 'danger');
            console.error('Error:', error);
        });
    });
}

// Real-time search suggestions
function setupSearchSuggestions(inputId, suggestionsId) {
    const input = document.getElementById(inputId);
    const suggestionsDiv = document.getElementById(suggestionsId);
    
    if (!input || !suggestionsDiv) return;
    
    input.addEventListener('input', function() {
        const query = this.value.trim();
        
        if (query.length < 2) {
            suggestionsDiv.innerHTML = '';
            return;
        }
        
        // This would be connected to backend search
        fetch(`../api/search.php?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                suggestionsDiv.innerHTML = '';
                data.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'suggestion-item';
                    div.textContent = item.text;
                    div.onclick = () => {
                        input.value = item.text;
                        suggestionsDiv.innerHTML = '';
                    };
                    suggestionsDiv.appendChild(div);
                });
            })
            .catch(error => console.error('Error:', error));
    });
}

// Init Global Search Autocomplete Function - Documents this helper's role in the parish management workflow.
function initGlobalSearchAutocomplete() {
    if (window.__parishAutocompleteReady) {
        return;
    }
    window.__parishAutocompleteReady = true;

    const selector = [
        'input[type="search"]',
        'input[name="q"]',
        'input[id*="search" i]',
        'input[placeholder*="search" i]',
        'input[placeholder*="Smart search" i]'
    ].join(',');

    const inputs = Array.from(document.querySelectorAll(selector)).filter(function(input) {
        return input.offsetParent !== null && !input.dataset.noAutocomplete;
    });

    inputs.forEach(function(input) {
        attachSearchAutocomplete(input);
    });
}

window.initGlobalSearchAutocomplete = initGlobalSearchAutocomplete;

// Attach Search Autocomplete Function - Documents this helper's role in the parish management workflow.
function attachSearchAutocomplete(input) {
    if (!input || input.dataset.autocompleteReady === 'true') {
        return;
    }

    input.dataset.autocompleteReady = 'true';
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('autocapitalize', 'none');
    input.setAttribute('spellcheck', 'false');

    const wrapper = document.createElement('div');
    wrapper.className = 'parish-autocomplete-wrap';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'parish-search-clear';
    clearButton.innerHTML = '<i class="fas fa-xmark"></i><span>Clear all</span>';
    clearButton.hidden = input.value.trim() === '';
    wrapper.appendChild(clearButton);

    const panel = document.createElement('div');
    panel.className = 'parish-autocomplete-panel';
    panel.hidden = true;
    wrapper.appendChild(panel);

    let timer = null;
    let activeIndex = -1;
    let suggestions = [];

    // Close Panel Function - Documents this helper's role in the parish management workflow.
    function closePanel() {
        panel.hidden = true;
        panel.innerHTML = '';
        activeIndex = -1;
        suggestions = [];
    }

    // Update Clear Button Function - Documents this helper's role in the parish management workflow.
    function updateClearButton() {
        clearButton.hidden = input.value.trim() === '';
    }

    // Clear Search Function - Documents this helper's role in the parish management workflow.
    function clearSearch(includeFormFilters) {
        input.value = '';
        if (includeFormFilters && input.form) {
            Array.from(input.form.elements).forEach(function(field) {
                if (field === input || field.type === 'hidden' || field.type === 'submit' || field.type === 'button') {
                    return;
                }
                if (field.matches('input[type="text"], input[type="search"], input:not([type]), textarea, select')) {
                    field.value = '';
                }
            });
        }
        closePanel();
        updateClearButton();
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.focus();
    }

    // Set Active Function - Documents this helper's role in the parish management workflow.
    function setActive(index) {
        const items = panel.querySelectorAll('.parish-autocomplete-item');
        items.forEach(function(item, itemIndex) {
            item.classList.toggle('is-active', itemIndex === index);
        });
        activeIndex = index;
    }

    // Choose Suggestion Function - Documents this helper's role in the parish management workflow.
    function chooseSuggestion(item) {
        input.value = item.label || '';
        closePanel();
        if (item.url) {
            window.location.href = item.url;
        } else if (input.form) {
            input.form.submit();
        }
    }

    // Render Function - Documents this helper's role in the parish management workflow.
    function render(items) {
        suggestions = items || [];
        panel.innerHTML = '';

        const header = document.createElement('div');
        header.className = 'parish-autocomplete-header';
        header.innerHTML = '<span>Suggestions</span>';
        const clearAll = document.createElement('button');
        clearAll.type = 'button';
        clearAll.textContent = 'Clear all';
        clearAll.addEventListener('mousedown', function(event) {
            event.preventDefault();
            clearSearch(true);
        });
        header.appendChild(clearAll);
        panel.appendChild(header);

        if (suggestions.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'parish-autocomplete-empty';
            empty.textContent = 'No matches found';
            panel.appendChild(empty);
            panel.hidden = false;
            return;
        }

        suggestions.forEach(function(item, index) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'parish-autocomplete-item';
            button.innerHTML = `
                <span class="parish-autocomplete-icon"><i class="fas ${item.icon || 'fa-search'}"></i></span>
                <span class="parish-autocomplete-text">
                    <strong>${escapeAutocompleteHtml(item.label || '')}</strong>
                    <small>${escapeAutocompleteHtml(item.meta || '')}</small>
                </span>
            `;
            button.addEventListener('mousedown', function(event) {
                event.preventDefault();
                chooseSuggestion(item);
            });
            button.addEventListener('mouseenter', function() {
                setActive(index);
            });
            panel.appendChild(button);
        });

        panel.hidden = false;
        setActive(-1);
    }

    // Fetch Suggestions Function - Documents this helper's role in the parish management workflow.
    function fetchSuggestions(query) {
        let url = '../api/search-suggestions.php?q=' + encodeURIComponent(query);
        if (input.dataset.suggestionScope) {
            url += '&scope=' + encodeURIComponent(input.dataset.suggestionScope);
        }

        fetch(url, {
            headers: { 'Accept': 'application/json' }
        })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                render(data.suggestions || []);
            })
            .catch(function() {
                closePanel();
            });
    }

    input.addEventListener('input', function() {
        const query = input.value.trim();
        window.clearTimeout(timer);
        updateClearButton();

        if (query.length < 1) {
            closePanel();
            return;
        }

        timer = window.setTimeout(function() {
            fetchSuggestions(query);
        }, 180);
    });

    input.addEventListener('keydown', function(event) {
        if (panel.hidden) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive(Math.min(activeIndex + 1, suggestions.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive(Math.max(activeIndex - 1, 0));
        } else if (event.key === 'Enter' && activeIndex >= 0 && suggestions[activeIndex]) {
            event.preventDefault();
            chooseSuggestion(suggestions[activeIndex]);
        } else if (event.key === 'Escape') {
            closePanel();
        }
    });

    input.addEventListener('focus', function() {
        updateClearButton();
        if (input.value.trim().length >= 1) {
            fetchSuggestions(input.value.trim());
        }
    });

    clearButton.addEventListener('mousedown', function(event) {
        event.preventDefault();
        clearSearch(true);
    });

    document.addEventListener('mousedown', function(event) {
        if (!wrapper.contains(event.target)) {
            closePanel();
        }
    });
}

// Escape Autocomplete Html Function - Documents this helper's role in the parish management workflow.
function escapeAutocompleteHtml(value) {
    return String(value).replace(/[&<>"']/g, function(char) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char];
    });
}

// Suppress Saved Info On Request Inputs Function - Documents this helper's role in the parish management workflow.
function suppressSavedInfoOnRequestInputs() {
    const selector = [
        'input[type="text"]',
        'input[type="search"]',
        'input[name="q"]',
        'input[placeholder*="search" i]',
        'input[placeholder*="location" i]',
        'input[placeholder*="name" i]',
        'textarea'
    ].join(',');

    document.querySelectorAll(selector).forEach(function(field) {
        if (field.dataset.allowBrowserAutocomplete === 'true') {
            return;
        }
        if (field.matches('input[type="search"], input[name="q"], input[placeholder*="search" i]')) {
            field.setAttribute('autocomplete', 'off');
        } else {
            field.setAttribute('autocomplete', 'new-password');
        }
        field.setAttribute('autocapitalize', 'none');
        field.setAttribute('spellcheck', 'false');
    });
}
