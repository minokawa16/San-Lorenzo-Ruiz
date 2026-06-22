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

});

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
function showNotification(message, type = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.role = 'alert';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.page-content') || document.body;
    container.insertBefore(alertDiv, container.firstChild);
}

// Confirm delete
function confirmDelete(message = 'Are you sure you want to delete this item?') {
    return confirm(message);
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
