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

    // ==========================================
    // Real-Time Schedule Double-Booking Prevention
    // ==========================================
    window.hasScheduleConflictState = false;
    window.lastConflictMessage = '';

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    function initScheduleConflictChecker() {
        const preferredTime = document.getElementById('preferred_time');
        const locationInput = document.getElementById('location');
        if (!preferredTime || !locationInput) {
            return;
        }

        const dateInputs = [
            document.getElementById('preferred_date'),
            document.getElementById('service_date'),
            document.getElementById('general_service_date'),
            document.getElementById('baptism_date'),
            document.getElementById('marriage_wedding_date'),
            document.getElementById('patronal_fiesta_date')
        ].filter(Boolean);

        function getCurrentDate() {
            for (let i = 0; i < dateInputs.length; i++) {
                const val = dateInputs[i].value ? dateInputs[i].value.trim() : '';
                if (val && dateInputs[i].type !== 'hidden') {
                    return val;
                }
            }
            const fallback = document.getElementById('preferred_date');
            return fallback ? fallback.value.trim() : '';
        }

        // Create feedback container if not present
        let feedbackContainer = document.getElementById('scheduleConflictFeedback');
        if (!feedbackContainer) {
            feedbackContainer = document.createElement('div');
            feedbackContainer.id = 'scheduleConflictFeedback';
            feedbackContainer.className = 'schedule-conflict-notice';
            feedbackContainer.style.display = 'none';
            feedbackContainer.setAttribute('aria-live', 'polite');

            const timeCol = preferredTime.closest('.col-md-6') || preferredTime.parentElement;
            if (timeCol) {
                timeCol.appendChild(feedbackContainer);
            }
        }

        // Create occupied slots container if not present
        let occupiedContainer = document.getElementById('occupiedSlotsNotice');
        if (!occupiedContainer) {
            occupiedContainer = document.createElement('div');
            occupiedContainer.id = 'occupiedSlotsNotice';
            occupiedContainer.className = 'occupied-slots-container';
            occupiedContainer.style.display = 'none';

            const timeCol = preferredTime.closest('.col-md-6') || preferredTime.parentElement;
            if (timeCol) {
                timeCol.appendChild(occupiedContainer);
            }
        }

        let debounceTimer = null;
        let lastCheckedKey = '';

        async function evaluateScheduleAvailability() {
            const curDate = getCurrentDate();
            const curTime = preferredTime.value ? preferredTime.value.trim() : '';
            const curLoc = locationInput.value ? locationInput.value.trim() : '';

            if (!curDate) {
                feedbackContainer.style.display = 'none';
                occupiedContainer.style.display = 'none';
                preferredTime.classList.remove('is-invalid', 'is-valid');
                window.hasScheduleConflictState = false;
                window.lastConflictMessage = '';
                return;
            }

            const checkKey = `${curDate}|${curTime}|${curLoc}`;
            if (checkKey === lastCheckedKey) {
                return;
            }
            lastCheckedKey = checkKey;

            // 1. Fetch occupied slots for the selected date & location
            try {
                const occUrl = `../api/check-schedule-conflict.php?action=occupied_slots&date=${encodeURIComponent(curDate)}&location=${encodeURIComponent(curLoc)}`;
                const occRes = await fetch(occUrl, { credentials: 'same-origin' });
                if (occRes.ok) {
                    const occData = await occRes.json();
                    if (occData && occData.occupied_times && occData.occupied_times.length > 0) {
                        occupiedContainer.innerHTML = `
                            <div class="occupied-slots-header">
                                <i class="fas fa-calendar-xmark text-danger"></i>
                                <span>Already occupied on this date at this location:</span>
                            </div>
                            <div class="occupied-slots-pills">
                                ${occData.slots.map(s => `
                                    <span class="occupied-slot-pill" title="${escapeHtml(s.title || 'Reserved')}">
                                        <i class="fas fa-clock"></i> ${escapeHtml(s.time_display || s.time)}
                                        <span class="badge-taken">Occupied</span>
                                    </span>
                                `).join('')}
                            </div>
                            <div class="form-text text-muted mt-1 small">Please choose another available schedule.</div>
                        `;
                        occupiedContainer.style.display = 'block';
                    } else {
                        occupiedContainer.style.display = 'none';
                    }
                }
            } catch (err) {
                console.warn('Unable to load occupied slots:', err);
            }

            // 2. If time is selected, check exact conflict
            if (!curTime) {
                feedbackContainer.style.display = 'none';
                preferredTime.classList.remove('is-invalid', 'is-valid');
                window.hasScheduleConflictState = false;
                window.lastConflictMessage = '';
                return;
            }

            try {
                const chkUrl = `../api/check-schedule-conflict.php?action=check&date=${encodeURIComponent(curDate)}&time=${encodeURIComponent(curTime)}&location=${encodeURIComponent(curLoc || 'Main Church')}`;
                const chkRes = await fetch(chkUrl, { credentials: 'same-origin' });
                const chkData = await chkRes.json();

                if (chkData && chkData.has_conflict) {
                    window.hasScheduleConflictState = true;
                    window.lastConflictMessage = chkData.message;
                    preferredTime.classList.remove('is-valid');
                    preferredTime.classList.add('is-invalid');
                    feedbackContainer.className = 'schedule-conflict-notice';
                    feedbackContainer.innerHTML = `
                        <i class="fas fa-triangle-exclamation text-danger mt-1"></i>
                        <div>
                            <strong>Schedule Occupied:</strong> ${escapeHtml(chkData.message)}
                        </div>
                    `;
                    feedbackContainer.style.display = 'flex';
                } else {
                    window.hasScheduleConflictState = false;
                    window.lastConflictMessage = '';
                    preferredTime.classList.remove('is-invalid');
                    preferredTime.classList.add('is-valid');
                    feedbackContainer.className = 'schedule-conflict-notice is-available';
                    feedbackContainer.innerHTML = `
                        <i class="fas fa-circle-check text-success mt-1"></i>
                        <div>This date, time, and location schedule slot is currently available!</div>
                    `;
                    feedbackContainer.style.display = 'flex';
                    window.setTimeout(() => {
                        if (!window.hasScheduleConflictState && feedbackContainer.classList.contains('is-available')) {
                            feedbackContainer.style.display = 'none';
                        }
                    }, 4000);
                }
            } catch (chkErr) {
                console.warn('Schedule conflict check error:', chkErr);
            }
        }

        function triggerDebouncedCheck() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(evaluateScheduleAvailability, 250);
        }

        preferredTime.addEventListener('change', triggerDebouncedCheck);
        preferredTime.addEventListener('input', triggerDebouncedCheck);
        locationInput.addEventListener('input', triggerDebouncedCheck);
        locationInput.addEventListener('change', triggerDebouncedCheck);

        dateInputs.forEach(d => {
            d.addEventListener('change', triggerDebouncedCheck);
            d.addEventListener('input', triggerDebouncedCheck);
        });

        // Initial check if values are prepopulated
        if (getCurrentDate()) {
            triggerDebouncedCheck();
        }

        // Intercept form submission or review step if conflict exists
        const requestForm = preferredTime.closest('form');
        if (requestForm) {
            requestForm.addEventListener('submit', function(e) {
                if (window.hasScheduleConflictState) {
                    e.preventDefault();
                    e.stopPropagation();
                    preferredTime.classList.add('is-invalid');
                    preferredTime.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    preferredTime.focus();
                    const alertMsg = window.lastConflictMessage || 'This schedule is already occupied. Please choose another available date or time.';
                    if (typeof ParishToast !== 'undefined' && typeof ParishToast.show === 'function') {
                        ParishToast.show({ title: 'Schedule Conflict', message: alertMsg, type: 'error', duration: 7000 });
                    } else {
                        alert(alertMsg);
                    }
                    if (typeof window.resetSubmitLoadingStates === 'function') {
                        window.resetSubmitLoadingStates(requestForm);
                    }
                    return false;
                }
            }, true);
        }

        const reviewBtn = document.getElementById('submitRequestBtn');
        if (reviewBtn) {
            reviewBtn.addEventListener('click', function(e) {
                if (window.hasScheduleConflictState) {
                    e.preventDefault();
                    e.stopPropagation();
                    preferredTime.classList.add('is-invalid');
                    preferredTime.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    preferredTime.focus();
                    const alertMsg = window.lastConflictMessage || 'This schedule is already occupied. Please choose another available date or time.';
                    if (typeof ParishToast !== 'undefined' && typeof ParishToast.show === 'function') {
                        ParishToast.show({ title: 'Schedule Conflict', message: alertMsg, type: 'error', duration: 7000 });
                    } else {
                        alert(alertMsg);
                    }
                    if (typeof window.resetSubmitLoadingStates === 'function') {
                        window.resetSubmitLoadingStates(requestForm);
                    }
                    return false;
                }
            }, true);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initScheduleConflictChecker);
    } else {
        initScheduleConflictChecker();
    }
})();
