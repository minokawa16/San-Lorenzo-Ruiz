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

    const maxFileBytes = 5 * 1024 * 1024; // 5 MB
    const allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    const invalidFileMsg = 'Only PDF or image files (JPG, PNG, WEBP) up to 5 MB are allowed. Please convert your document and upload again.';

    function validateSingleFile(f) {
        if (!f) return false;
        if (f.size > maxFileBytes || f.size <= 0) return false;
        const ext = (f.name || '').split('.').pop().toLowerCase();
        if (!allowedExtensions.includes(ext)) return false;
        if (f.type && !f.type.startsWith('image/') && f.type.toLowerCase() !== 'application/pdf') return false;
        return true;
    }

    function validateFileList(files) {
        if (!files || files.length === 0) return true;
        for (let i = 0; i < files.length; i++) {
            if (!validateSingleFile(files[i])) return false;
        }
        return true;
    }

    function showFileError(message) {
        if (typeof ParishToast !== 'undefined' && typeof ParishToast.show === 'function') {
            ParishToast.show({ title: 'Invalid File', message: message, type: 'error', duration: 7000 });
        } else {
            alert(message);
        }
    }

    // Render File(s) Function - Handles both single and multiple files with thumbnail / PDF icon previews
    function renderFiles(files) {
        if (!files || files.length === 0) {
            if (filePreview) filePreview.classList.remove('is-visible');
            return;
        }

        if (!validateFileList(files)) {
            if (fileInput) fileInput.value = '';
            if (filePreview) filePreview.classList.remove('is-visible');
            showFileError(invalidFileMsg);
            return;
        }

        if (!filePreview) return;
        filePreview.classList.add('is-visible');

        const fileListEl = document.getElementById('fileList');
        if (fileListEl) {
            fileListEl.innerHTML = '';
            Array.from(files).forEach(function(f) {
                const ext = (f.name || '').split('.').pop().toLowerCase();
                const isPdf = ext === 'pdf' || f.type === 'application/pdf';
                const itemEl = document.createElement('div');
                itemEl.className = 'd-flex align-items-center gap-2 mb-1.5 p-1 rounded';
                itemEl.style.background = '#f8fafc';
                itemEl.style.border = '1px solid #e2e8f0';
                const sizeMb = (f.size / (1024 * 1024)).toFixed(2);
                if (isPdf) {
                    itemEl.innerHTML = `
                        <i class="fas fa-file-pdf text-danger fa-2x flex-shrink-0 ms-1"></i>
                        <div class="text-truncate ms-1" style="flex:1;">
                            <span class="fw-bold text-dark d-block text-truncate" style="font-size: 13px;">${escapeHtml(f.name)}</span>
                            <small class="text-muted">${sizeMb} MB</small>
                        </div>
                    `;
                } else {
                    const thumbUrl = URL.createObjectURL(f);
                    itemEl.innerHTML = `
                        <img src="${thumbUrl}" alt="Thumbnail" class="rounded flex-shrink-0 shadow-sm" style="width: 36px; height: 36px; object-fit: cover;">
                        <div class="text-truncate ms-1" style="flex:1;">
                            <span class="fw-bold text-dark d-block text-truncate" style="font-size: 13px;">${escapeHtml(f.name)}</span>
                            <small class="text-muted">${sizeMb} MB</small>
                        </div>
                    `;
                }
                fileListEl.appendChild(itemEl);
            });
        } else {
            if (files.length === 1) {
                if (fileName) fileName.textContent = files[0].name;
                if (fileSize) fileSize.textContent = (files[0].size / 1024 / 1024).toFixed(2) + ' MB selected';
            } else {
                let total_size = 0;
                for (let i = 0; i < files.length; i++) total_size += files[i].size;
                if (fileName) fileName.textContent = files.length + ' files selected';
                if (fileSize) fileSize.textContent = (total_size / 1024 / 1024).toFixed(2) + ' MB total';
            }
        }
    }

    if (fileInput) {
        fileInput.addEventListener('change', function() {
            renderFiles(fileInput.files);
        });
    }

    // Bind validation to any other file inputs on the form
    document.querySelectorAll('input[type="file"]').forEach(function(inp) {
        if (inp === fileInput) return;
        inp.addEventListener('change', function() {
            if (inp.files && inp.files.length > 0) {
                if (!validateFileList(inp.files)) {
                    inp.value = '';
                    showFileError(invalidFileMsg);
                }
            }
        });
    });

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
                if (!validateFileList(event.dataTransfer.files)) {
                    showFileError(invalidFileMsg);
                    return;
                }
                fileInput.files = event.dataTransfer.files;
                renderFiles(fileInput.files);
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            const allFileInputs = form.querySelectorAll('input[type="file"]');
            for (let i = 0; i < allFileInputs.length; i++) {
                const inp = allFileInputs[i];
                if (inp.files && inp.files.length > 0) {
                    if (!validateFileList(inp.files)) {
                        e.preventDefault();
                        e.stopPropagation();
                        inp.value = '';
                        showFileError(invalidFileMsg);
                        return false;
                    }
                }
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

        const requestForm = preferredTime.closest('form');

        const dateInputs = [
            document.getElementById('preferred_date'),
            document.getElementById('service_date'),
            document.getElementById('general_service_date'),
            document.getElementById('baptism_date'),
            document.getElementById('wedding_date'),
            document.getElementById('marriage_wedding_date'),
            document.getElementById('funeral_date_of_burial'),
            document.getElementById('funeral_burial_date'),
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

        function getExcludeId() {
            const el = document.querySelector('input[name="request_id"], input[name="exclude_id"], input[name="excludeId"]');
            if (el && el.value) return el.value.trim();
            const params = new URLSearchParams(window.location.search);
            return params.get('request_id') || params.get('id') || params.get('excludeId') || '';
        }

        function getManilaDateInfo() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const manilaNow = new Date(utc + (3600000 * 8));
            const y = manilaNow.getFullYear();
            const m = String(manilaNow.getMonth() + 1).padStart(2, '0');
            const d = String(manilaNow.getDate()).padStart(2, '0');
            return {
                todayDate: `${y}-${m}-${d}`,
                hour: manilaNow.getHours(),
                minute: manilaNow.getMinutes(),
                dateObj: manilaNow
            };
        }

        function updateSubmitButtonsConflictState(hasConflict) {
            const btns = [];
            const rBtn = document.getElementById('submitRequestBtn');
            const cBtn = document.getElementById('confirmServiceSubmit');
            if (rBtn) btns.push(rBtn);
            if (cBtn && !btns.includes(cBtn)) btns.push(cBtn);

            if (requestForm) {
                requestForm.querySelectorAll('button[type="submit"], .submit-request-btn').forEach(btn => {
                    if (!btns.includes(btn)) {
                        btns.push(btn);
                    }
                });
            }

            btns.forEach(btn => {
                if (!btn) return;
                if (hasConflict) {
                    btn.disabled = true;
                    btn.setAttribute('disabled', 'disabled');
                    btn.setAttribute('aria-disabled', 'true');
                    btn.classList.add('disabled-by-conflict');
                    btn.style.opacity = '0.52';
                    btn.style.filter = 'grayscale(0.4)';
                    btn.style.cursor = 'not-allowed';
                    if (!btn.getAttribute('data-original-title')) {
                        btn.setAttribute('data-original-title', btn.title || '');
                    }
                    btn.title = 'Cannot submit: this schedule is already occupied and not available.';
                } else {
                    btn.disabled = false;
                    btn.removeAttribute('disabled');
                    btn.removeAttribute('aria-disabled');
                    btn.classList.remove('disabled-by-conflict');
                    btn.style.opacity = '';
                    btn.style.filter = '';
                    btn.style.cursor = '';
                    const origTitle = btn.getAttribute('data-original-title');
                    if (origTitle !== null) {
                        btn.title = origTitle;
                    }
                }
            });
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

        // Handle clicks on suggestion chips inside feedbackContainer
        feedbackContainer.addEventListener('click', function(e) {
            const chip = e.target.closest('.suggestion-chip');
            if (chip && chip.dataset.time) {
                e.preventDefault();
                preferredTime.value = chip.dataset.time;
                preferredTime.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        function updateSelectOptionsAvailability(slots, curDate) {
            if (!preferredTime || preferredTime.tagName !== 'SELECT') return;

            const manila = getManilaDateInfo();
            const isToday = (curDate === manila.todayDate);

            Array.from(preferredTime.options).forEach(opt => {
                if (!opt.value) return;
                const cleanTime = opt.value.trim().substring(0, 5);
                const optParts = cleanTime.split(':');
                const optH = parseInt(optParts[0], 10);
                const optM = parseInt(optParts[1] || '0', 10);

                let baseLabel = opt.getAttribute('data-base-label');
                if (!baseLabel) {
                    baseLabel = opt.textContent.replace(/\s*\((Occupied|Past)\)/g, '').trim();
                    opt.setAttribute('data-base-label', baseLabel);
                }

                // Check past
                let isPast = false;
                if (curDate < manila.todayDate) {
                    isPast = true;
                } else if (isToday && (optH < manila.hour || (optH === manila.hour && optM <= manila.minute))) {
                    isPast = true;
                }

                // Check occupied
                let isOccupied = false;
                if (!isPast && Array.isArray(slots)) {
                    const reqStartSec = optH * 3600 + optM * 60;
                    const reqEndSec = reqStartSec + 3600; // fixed 60-min slot

                    for (const occ of slots) {
                        const sStr = occ.start || occ.time || occ.slot_time;
                        if (!sStr) continue;
                        const sParts = sStr.split(':');
                        const sSec = parseInt(sParts[0], 10) * 3600 + parseInt(sParts[1] || '0', 10) * 60;
                        let eSec = sSec + 3600;
                        if (occ.end || occ.slot_end_time) {
                            const eParts = (occ.end || occ.slot_end_time).split(':');
                            eSec = parseInt(eParts[0], 10) * 3600 + parseInt(eParts[1] || '0', 10) * 60;
                        }

                        // Core rule: newStart < existingEnd && newEnd > existingStart
                        if (reqStartSec < eSec && reqEndSec > sSec) {
                            isOccupied = true;
                            break;
                        }
                    }
                }

                if (isPast) {
                    opt.disabled = true;
                    opt.textContent = `${baseLabel} (Past)`;
                } else if (isOccupied) {
                    opt.disabled = true;
                    opt.textContent = `${baseLabel} (Occupied)`;
                } else {
                    opt.disabled = false;
                    opt.textContent = baseLabel;
                }
            });
        }

        let debounceTimer = null;
        let lastCheckedKey = '';
        let checkSequence = 0;

        async function evaluateScheduleAvailability() {
            const curDate = getCurrentDate();
            const curTime = preferredTime.value ? preferredTime.value.trim() : '';
            const curLoc = locationInput.value ? locationInput.value.trim() : '';
            const excludeId = getExcludeId();

            if (!curDate) {
                feedbackContainer.style.display = 'none';
                occupiedContainer.style.display = 'none';
                preferredTime.classList.remove('is-invalid', 'is-valid');
                window.hasScheduleConflictState = false;
                window.lastConflictMessage = '';
                updateSubmitButtonsConflictState(false);
                return;
            }

            const checkKey = `${curDate}|${curTime}|${curLoc}|${excludeId}`;
            if (checkKey === lastCheckedKey) {
                return;
            }
            lastCheckedKey = checkKey;
            const currentSeq = ++checkSequence;

            // 1. Fetch day availability snapshot (occupied slots & suggestions)
            try {
                const dayUrl = `../api/schedule/check.php?date=${encodeURIComponent(curDate)}&location=${encodeURIComponent(curLoc)}&excludeId=${encodeURIComponent(excludeId)}&suppress_409=1`;
                const dayRes = await fetch(dayUrl, { credentials: 'same-origin' });
                if (dayRes.ok && currentSeq === checkSequence) {
                    const dayData = await dayRes.json();
                    const slots = dayData.conflicts || dayData.slots || [];
                    updateSelectOptionsAvailability(slots, curDate);

                    if (slots.length > 0) {
                        occupiedContainer.innerHTML = `
                            <div class="occupied-slots-header">
                                <i class="fas fa-calendar-xmark text-danger"></i>
                                <span>Occupied slots on this date (60-minute duration):</span>
                            </div>
                            <div class="occupied-slots-pills">
                                ${slots.map(s => {
                                    const timeLabel = s.range_display || s.time_range || s.time_display || s.time;
                                    const title = s.title || 'Reserved';
                                    return `
                                        <span class="occupied-slot-pill" title="${escapeHtml(title)} (${escapeHtml(timeLabel)})">
                                            <i class="fas fa-clock"></i> ${escapeHtml(title)}: ${escapeHtml(timeLabel)}
                                            <span class="badge-taken">Occupied</span>
                                        </span>
                                    `;
                                }).join('')}
                            </div>
                            <div class="form-text text-muted mt-1 small">Bookings occupy 1-hour slots. Please choose an open hourly slot.</div>
                        `;
                        occupiedContainer.style.display = 'block';
                    } else {
                        occupiedContainer.style.display = 'none';
                    }
                }
            } catch (err) {
                console.warn('Unable to load day availability:', err);
            }

            if (currentSeq !== checkSequence) {
                return;
            }

            // 2. If no time selected yet, clear evaluation and return
            if (!curTime) {
                feedbackContainer.style.display = 'none';
                preferredTime.classList.remove('is-invalid', 'is-valid');
                window.hasScheduleConflictState = false;
                window.lastConflictMessage = '';
                updateSubmitButtonsConflictState(false);
                return;
            }

            // 3. Check conflict for this specific slot
            try {
                const chkUrl = `../api/schedule/check.php?date=${encodeURIComponent(curDate)}&time=${encodeURIComponent(curTime)}&location=${encodeURIComponent(curLoc || 'Main Church')}&excludeId=${encodeURIComponent(excludeId)}&suppress_409=1`;
                const chkRes = await fetch(chkUrl, { credentials: 'same-origin' });
                const chkData = await chkRes.json();

                if (currentSeq !== checkSequence) {
                    return;
                }

                const hasConflict = !chkData.available;

                if (hasConflict) {
                    window.hasScheduleConflictState = true;
                    const primaryMsg = chkData.message || 'This schedule is already occupied and not available. Please choose a different date or time.';
                    window.lastConflictMessage = primaryMsg;

                    preferredTime.classList.remove('is-valid');
                    preferredTime.classList.add('is-invalid');
                    updateSubmitButtonsConflictState(true);

                    feedbackContainer.className = 'schedule-conflict-notice';

                    // Build conflict bookings HTML
                    let conflictListHtml = '';
                    if (Array.isArray(chkData.conflicts) && chkData.conflicts.length > 0) {
                        conflictListHtml = `
                            <ul class="conflict-bookings-list">
                                ${chkData.conflicts.map(c => `
                                    <li class="conflict-item">
                                        <i class="fas fa-calendar-xmark text-danger"></i>
                                        <span><strong>${escapeHtml(c.title || 'Booking')}</strong>, ${escapeHtml(c.range_display || c.time_range || c.time)}</span>
                                    </li>
                                `).join('')}
                            </ul>
                        `;
                    }

                    // Build suggestions HTML
                    let suggestionsHtml = '';
                    if (Array.isArray(chkData.suggestions) && chkData.suggestions.length > 0) {
                        suggestionsHtml = `
                            <div class="conflict-suggestions">
                                <span class="suggestions-label"><i class="fas fa-lightbulb"></i> Nearest available slots on this day:</span>
                                <div class="suggestion-chips-group">
                                    ${chkData.suggestions.map(s => `
                                        <button type="button" class="suggestion-chip" data-time="${escapeHtml(s.time)}" title="Select ${escapeHtml(s.display_time || s.time)}">
                                            <i class="fas fa-clock"></i> ${escapeHtml(s.display_time || s.time)}
                                        </button>
                                    `).join('')}
                                </div>
                            </div>
                        `;
                    }

                    feedbackContainer.innerHTML = `
                        <i class="fas fa-triangle-exclamation text-danger mt-1"></i>
                        <div class="conflict-content">
                            <div class="conflict-title">This schedule is already occupied and not available. Please choose a different date or time.</div>
                            ${conflictListHtml}
                            ${suggestionsHtml}
                        </div>
                    `;
                    feedbackContainer.style.display = 'flex';
                } else {
                    window.hasScheduleConflictState = false;
                    window.lastConflictMessage = '';
                    preferredTime.classList.remove('is-invalid');
                    preferredTime.classList.add('is-valid');
                    updateSubmitButtonsConflictState(false);

                    feedbackContainer.className = 'schedule-conflict-notice is-available';
                    feedbackContainer.innerHTML = `
                        <i class="fas fa-circle-check text-success mt-1"></i>
                        <div class="conflict-content">
                            <div class="conflict-title text-success">This date, time, and location schedule slot is currently available!</div>
                        </div>
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

        // Form-level change delegation to catch dynamic date synchronizations
        if (requestForm) {
            requestForm.addEventListener('change', function(e) {
                if (e.target && (e.target.type === 'date' || e.target.type === 'radio')) {
                    triggerDebouncedCheck();
                }
            });
        }

        // Initial check if values are prepopulated
        if (getCurrentDate()) {
            triggerDebouncedCheck();
        }

        // Intercept form submission or review step if conflict exists
        if (requestForm) {
            requestForm.addEventListener('submit', function(e) {
                if (window.hasScheduleConflictState) {
                    e.preventDefault();
                    e.stopPropagation();
                    updateSubmitButtonsConflictState(true);
                    preferredTime.classList.add('is-invalid');
                    preferredTime.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    preferredTime.focus();
                    const alertMsg = window.lastConflictMessage || 'This schedule is already occupied and not available. Please choose a different date or time.';
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
                    updateSubmitButtonsConflictState(true);
                    preferredTime.classList.add('is-invalid');
                    preferredTime.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    preferredTime.focus();
                    const alertMsg = window.lastConflictMessage || 'This schedule is already occupied and not available. Please choose a different date or time.';
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
