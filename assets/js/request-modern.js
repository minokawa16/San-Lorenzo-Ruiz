/**
 * Modern Request Form Script - Manages uploads, previews, validation feedback, and submit states for request forms.
 */

(function() {
    const fileInput = document.getElementById('requirement_file') || document.getElementById('requirement_files');
    const uploadZone = document.getElementById('uploadZone');
    const filePreview = document.getElementById('filePreview');
    const fileName = document.getElementById('fileName');
    const fileSize = document.getElementById('fileSize');
    const form = document.querySelector('[data-modern-request-form]');
    const submitBtn = document.getElementById('submitRequestBtn');
    const searchInput = document.getElementById('requestSearchSelect');
    const optionsList = document.getElementById('requestTypeOptions');
    const radios = document.querySelectorAll('input[name="request_type"]');
    const otherRequestWrap = document.querySelector('[data-other-request-wrap]');
    const otherRequestInput = otherRequestWrap ? otherRequestWrap.querySelector('input, textarea') : null;

    function syncOtherRequestField() {
        if (!otherRequestWrap || !otherRequestInput) {
            return;
        }
        const selected = document.querySelector('input[name="request_type"]:checked');
        const showOther = selected && selected.value === 'other_blessing';
        otherRequestWrap.hidden = !showOther;
        otherRequestInput.required = Boolean(showOther);
        otherRequestInput.setAttribute('aria-required', showOther ? 'true' : 'false');
    }

    radios.forEach(function(radio) {
        radio.addEventListener('change', syncOtherRequestField);
    });
    syncOtherRequestField();

    // Render File(s) Function - Handles both single and multiple files
    function renderFiles(files) {
        if (!files || files.length === 0 || !filePreview) {
            return;
        }
        filePreview.classList.add('is-visible');
        
        if (files.length === 1) {
            // Single file
            fileName.textContent = files[0].name;
            fileSize.textContent = (files[0].size / 1024 / 1024).toFixed(2) + ' MB selected';
        } else {
            // Multiple files
            let total_size = 0;
            let file_names = [];
            for (let i = 0; i < files.length; i++) {
                total_size += files[i].size;
                file_names.push(files[i].name);
            }
            
            fileName.textContent = file_names.length + ' files selected';
            fileSize.textContent = (total_size / 1024 / 1024).toFixed(2) + ' MB total';
        }
    }

    if (fileInput) {
        fileInput.addEventListener('change', function() {
            renderFiles(fileInput.files);
        });
    }

    if (uploadZone) {
        ['dragenter', 'dragover'].forEach(function(eventName) {
            uploadZone.addEventListener(eventName, function(event) {
                event.preventDefault();
                uploadZone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'drop'].forEach(function(eventName) {
            uploadZone.addEventListener(eventName, function(event) {
                event.preventDefault();
                uploadZone.classList.remove('is-dragover');
            });
        });

        uploadZone.addEventListener('drop', function(event) {
            if (event.dataTransfer.files.length && fileInput) {
                fileInput.files = event.dataTransfer.files;
                renderFiles(fileInput.files);
            }
        });
    }

    if (searchInput && optionsList) {
        // Sync Search Selection Function - Documents this helper's role in the parish management workflow.
        function syncSearchSelection() {
            const option = Array.from(optionsList.querySelectorAll('option')).find(function(item) {
                return item.value.toLowerCase() === searchInput.value.toLowerCase();
            });
            const value = option ? option.dataset.value : '';
            const match = value ? document.querySelector('input[name="request_type"][value="' + value.replace(/"/g, '\\"') + '"]') : null;
            if (match) {
                match.checked = true;
                match.focus();
                match.dispatchEvent(new Event('change', {bubbles: true}));
            }
        }

        searchInput.addEventListener('change', syncSearchSelection);
        searchInput.addEventListener('input', syncSearchSelection);

        radios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                const option = optionsList.querySelector('option[data-value="' + radio.value.replace(/"/g, '\\"') + '"]');
                searchInput.value = option ? option.value : '';
            });
        });
    }

    if (form && submitBtn) {
        form.addEventListener('submit', function(event) {
            window.setTimeout(function() {
                if (event.defaultPrevented) {
                    return;
                }
                const activeSubmit = event.submitter || submitBtn;
                activeSubmit.classList.add('is-loading');
                activeSubmit.disabled = true;

                // Fail-safe: Re-enable button after 15 seconds if navigation did not occur
                window.setTimeout(function() {
                    if (activeSubmit && activeSubmit.classList.contains('is-loading')) {
                        activeSubmit.classList.remove('is-loading');
                        activeSubmit.disabled = false;
                    }
                }, 15000);
            }, 0);
        });
    }

    // Reset button states on bfcache page restore (Back/Forward navigation)
    window.addEventListener('pageshow', function() {
        document.querySelectorAll('.submit-request-btn').forEach(function(btn) {
            btn.classList.remove('is-loading');
            btn.disabled = false;
        });
    });

    // Global reset helper for request forms
    window.resetSubmitLoadingStates = function(scope) {
        const root = scope || document;
        root.querySelectorAll('.submit-request-btn').forEach(function(btn) {
            btn.classList.remove('is-loading');
            btn.disabled = false;
        });
    };
})();
