<?php
/**
 * Parish Calendar Picker Modal Component
 * Used across service and blessing request forms.
 * Allows parishioners to view upcoming parish schedules and pick an open date.
 */
?>
<!-- Parish Calendar Picker Modal -->
<div class="modal fade" id="parishCalendarPickerModal" tabindex="-1" aria-labelledby="parishCalendarModalLabel" aria-hidden="true" style="z-index: 1065;">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content parish-calendar-modal-content">
            <!-- Modal Header -->
            <div class="modal-header parish-calendar-modal-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="parish-calendar-modal-emblem">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div>
                        <h5 class="modal-title mb-0 fw-bold" id="parishCalendarModalLabel">Parish Calendar &amp; Schedule</h5>
                        <small class="parish-calendar-modal-sub">Check available dates and public parish services</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body parish-calendar-modal-body p-3 p-md-4">
                <!-- Toolbar: Month Nav & Today Jump -->
                <div class="parish-calendar-nav-bar">
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm parish-cal-nav-btn" id="parishCalPrevBtn" aria-label="Previous month">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <h4 class="mb-0 fw-bold parish-cal-month-title" id="parishCalMonthTitle">October 2026</h4>
                        <button type="button" class="btn btn-sm parish-cal-nav-btn" id="parishCalNextBtn" aria-label="Next month">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm parish-cal-today-btn" id="parishCalTodayBtn">
                            <i class="fas fa-calendar-day me-1"></i> Today
                        </button>
                    </div>
                </div>

                <!-- Legend -->
                <div class="parish-calendar-legend">
                    <span class="legend-item"><span class="legend-dot is-available"></span> Available Date</span>
                    <span class="legend-item"><span class="legend-dot is-event"></span> Parish Schedule / Busy</span>
                    <span class="legend-item"><span class="legend-dot is-today"></span> Today</span>
                    <span class="legend-item"><span class="legend-dot is-past"></span> Past / Unavailable</span>
                </div>

                <!-- Calendar Weekdays Row -->
                <div class="parish-calendar-weekdays">
                    <span>Sun</span>
                    <span>Mon</span>
                    <span>Tue</span>
                    <span>Wed</span>
                    <span>Thu</span>
                    <span>Fri</span>
                    <span>Sat</span>
                </div>

                <!-- Calendar Days Grid -->
                <div class="parish-calendar-grid" id="parishCalGrid" role="grid" aria-label="Days of the month">
                    <!-- Populated by JavaScript -->
                </div>

                <!-- Selected Date Info Banner -->
                <div class="parish-cal-selection-card mt-3" id="parishCalSelectionCard">
                    <div class="d-flex align-items-start gap-2.5">
                        <i class="fas fa-calendar-check parish-cal-sel-icon"></i>
                        <div class="flex-grow-1">
                            <strong class="d-block text-dark" id="parishCalSelectedDateText">No date selected yet</strong>
                            <span class="small text-muted" id="parishCalSelectedDetailText">Click an upcoming date in the calendar above to select it.</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer parish-calendar-modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-parish-apply-date px-3" id="parishCalApplyBtn" disabled>
                    <i class="fas fa-check me-1.5"></i> Use Selected Date
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    let currentTargetInput = null;
    let viewingDate = new Date();
    let selectedDateStr = null;
    let eventsByDate = {};
    let modalInstance = null;

    const modalEl = document.getElementById('parishCalendarPickerModal');
    if (!modalEl) return;

    const monthTitleEl = document.getElementById('parishCalMonthTitle');
    const gridEl = document.getElementById('parishCalGrid');
    const prevBtn = document.getElementById('parishCalPrevBtn');
    const nextBtn = document.getElementById('parishCalNextBtn');
    const todayBtn = document.getElementById('parishCalTodayBtn');
    const applyBtn = document.getElementById('parishCalApplyBtn');
    const selectedTextEl = document.getElementById('parishCalSelectedDateText');
    const selectedDetailEl = document.getElementById('parishCalSelectedDetailText');

    function padZero(num) {
        return String(num).padStart(2, '0');
    }

    function formatIsoDate(year, month, day) {
        return year + '-' + padZero(month + 1) + '-' + padZero(day);
    }

    function getTodayIso() {
        const d = new Date();
        return formatIsoDate(d.getFullYear(), d.getMonth(), d.getDate());
    }

    function formatHumanDate(isoStr) {
        if (!isoStr) return '';
        const parts = isoStr.split('-');
        if (parts.length !== 3) return isoStr;
        const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        return d.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }

    // Fetch month events to show event dots on calendar days
    async function loadMonthEvents(year, month) {
        eventsByDate = {};
        const startDate = formatIsoDate(year, month, 1);
        const lastDay = new Date(year, month + 1, 0).getDate();
        const endDate = formatIsoDate(year, month, lastDay);

        try {
            const url = '../api/calendar-events.php?start=' + encodeURIComponent(startDate) + '&end=' + encodeURIComponent(endDate);
            const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (res.ok) {
                const list = await res.json();
                if (Array.isArray(list)) {
                    list.forEach(function(ev) {
                        const dStr = (ev.start || ev.date || ev.event_date || '').substring(0, 10);
                        if (dStr) {
                            if (!eventsByDate[dStr]) eventsByDate[dStr] = [];
                            eventsByDate[dStr].push(ev.title || 'Schedule');
                        }
                    });
                }
            }
        } catch (err) {
            console.warn('Unable to load parish calendar events for modal:', err);
        }
    }

    // Render calendar grid for viewingDate
    function renderCalendar() {
        const year = viewingDate.getFullYear();
        const month = viewingDate.getMonth();

        const monthName = viewingDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        if (monthTitleEl) {
            monthTitleEl.textContent = monthName;
        }

        if (!gridEl) return;
        gridEl.innerHTML = '';

        const todayIso = getTodayIso();
        const minIso = currentTargetInput && currentTargetInput.getAttribute('min') ? currentTargetInput.getAttribute('min') : todayIso;
        const maxIso = currentTargetInput && currentTargetInput.getAttribute('max') ? currentTargetInput.getAttribute('max') : null;

        const firstDayIndex = new Date(year, month, 1).getDay(); // 0 = Sun
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const daysInPrevMonth = new Date(year, month, 0).getDate();

        // 1. Previous month trailing days
        for (let i = firstDayIndex - 1; i >= 0; i--) {
            const dayNum = daysInPrevMonth - i;
            const cell = document.createElement('div');
            cell.className = 'parish-cal-day is-outside';
            cell.innerHTML = '<span class="day-num">' + dayNum + '</span>';
            gridEl.appendChild(cell);
        }

        // 2. Current month days
        for (let day = 1; day <= daysInMonth; day++) {
            const iso = formatIsoDate(year, month, day);
            const isToday = (iso === todayIso);
            const isPast = (iso < minIso);
            const isFutureMax = maxIso && (iso > maxIso);
            const isDisabled = isPast || isFutureMax;
            const isSelected = (iso === selectedDateStr);
            const dayEvents = eventsByDate[iso] || [];

            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'parish-cal-day' +
                (isToday ? ' is-today' : '') +
                (isSelected ? ' is-selected' : '') +
                (isDisabled ? ' is-disabled' : ' is-available') +
                (dayEvents.length > 0 ? ' has-events' : '');

            cell.setAttribute('data-date', iso);
            cell.disabled = isDisabled;

            let dotsHtml = '';
            if (dayEvents.length > 0) {
                dotsHtml = '<span class="event-dot" title="' + dayEvents.length + ' event(s)"></span>';
            }

            cell.innerHTML = '<span class="day-num">' + day + '</span>' + dotsHtml;

            if (!isDisabled) {
                cell.addEventListener('click', function() {
                    selectDate(iso);
                });
                cell.addEventListener('dblclick', function() {
                    selectDate(iso);
                    applySelectedDate();
                });
            }

            gridEl.appendChild(cell);
        }

        // 3. Next month leading days to complete the 7-col grid
        const totalRendered = firstDayIndex + daysInMonth;
        const remainingCells = (7 - (totalRendered % 7)) % 7;
        for (let day = 1; day <= remainingCells; day++) {
            const cell = document.createElement('div');
            cell.className = 'parish-cal-day is-outside';
            cell.innerHTML = '<span class="day-num">' + day + '</span>';
            gridEl.appendChild(cell);
        }

        updateSelectionBanner();
    }

    function selectDate(iso) {
        selectedDateStr = iso;

        // Update active class on grid
        const allDays = gridEl.querySelectorAll('.parish-cal-day');
        allDays.forEach(function(d) {
            if (d.getAttribute('data-date') === iso) {
                d.classList.add('is-selected');
            } else {
                d.classList.remove('is-selected');
            }
        });

        if (applyBtn) {
            applyBtn.disabled = false;
        }

        updateSelectionBanner();
    }

    function updateSelectionBanner() {
        if (!selectedDateStr) {
            if (selectedTextEl) selectedTextEl.textContent = 'No date selected yet';
            if (selectedDetailEl) selectedDetailEl.textContent = 'Click an available date in the calendar to pick it.';
            if (applyBtn) applyBtn.disabled = true;
            return;
        }

        const human = formatHumanDate(selectedDateStr);
        const dayEvents = eventsByDate[selectedDateStr] || [];

        if (selectedTextEl) {
            selectedTextEl.textContent = human;
        }
        if (selectedDetailEl) {
            if (dayEvents.length > 0) {
                selectedDetailEl.innerHTML = '<span class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i>Date selected.</span> ' +
                    'Parish schedule notice: ' + escapeHtml(dayEvents[0]) + (dayEvents.length > 1 ? ' (+' + (dayEvents.length - 1) + ' more)' : '');
            } else {
                selectedDetailEl.innerHTML = '<span class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i>Date selected.</span> Ready to use for your requested schedule.';
            }
        }
        if (applyBtn) {
            applyBtn.disabled = false;
        }
    }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function(s) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[s];
        });
    }

    function applySelectedDate() {
        if (!selectedDateStr || !currentTargetInput) return;

        currentTargetInput.value = selectedDateStr;

        // Dispatch input & change events for validation and conflict checking
        currentTargetInput.dispatchEvent(new Event('input', { bubbles: true }));
        currentTargetInput.dispatchEvent(new Event('change', { bubbles: true }));

        // Hide modal
        if (modalInstance) {
            modalInstance.hide();
        }

        if (typeof ParishToast !== 'undefined' && typeof ParishToast.show === 'function') {
            ParishToast.show({
                title: 'Schedule Date Selected',
                message: formatHumanDate(selectedDateStr) + ' set as your preferred date.',
                type: 'success',
                duration: 4000
            });
        }
    }

    // Open modal API
    window.openParishCalendarModal = async function(targetInputOrSelector) {
        let inputEl = null;
        if (typeof targetInputOrSelector === 'string') {
            inputEl = document.querySelector(targetInputOrSelector);
        } else if (targetInputOrSelector instanceof HTMLElement) {
            inputEl = targetInputOrSelector;
        }

        currentTargetInput = inputEl;
        selectedDateStr = (inputEl && inputEl.value) ? inputEl.value : null;

        if (selectedDateStr) {
            const parts = selectedDateStr.split('-');
            if (parts.length === 3) {
                viewingDate = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, 1);
            }
        } else {
            viewingDate = new Date();
        }

        await loadMonthEvents(viewingDate.getFullYear(), viewingDate.getMonth());
        renderCalendar();

        if (window.bootstrap && window.bootstrap.Modal) {
            modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
            modalInstance.show();
        } else {
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
        }
    };

    // Event listeners for month buttons
    if (prevBtn) {
        prevBtn.addEventListener('click', async function() {
            viewingDate.setMonth(viewingDate.getMonth() - 1);
            await loadMonthEvents(viewingDate.getFullYear(), viewingDate.getMonth());
            renderCalendar();
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', async function() {
            viewingDate.setMonth(viewingDate.getMonth() + 1);
            await loadMonthEvents(viewingDate.getFullYear(), viewingDate.getMonth());
            renderCalendar();
        });
    }

    if (todayBtn) {
        todayBtn.addEventListener('click', async function() {
            viewingDate = new Date();
            await loadMonthEvents(viewingDate.getFullYear(), viewingDate.getMonth());
            renderCalendar();
        });
    }

    if (applyBtn) {
        applyBtn.addEventListener('click', applySelectedDate);
    }

    // Delegated click handler for any .btn-parish-calendar-trigger or .view-calendar-link
    document.addEventListener('click', function(event) {
        const trigger = event.target.closest('.btn-parish-calendar-trigger, .view-calendar-link, [data-open-parish-calendar]');
        if (trigger) {
            event.preventDefault();
            let targetSelector = trigger.getAttribute('data-calendar-target');
            let targetInput = null;

            if (targetSelector) {
                targetInput = document.querySelector(targetSelector);
            }

            if (!targetInput) {
                const parentGroup = trigger.closest('.date-input-with-calendar, .col-md-6, .mb-3, .form-group, div');
                if (parentGroup) {
                    targetInput = parentGroup.querySelector('input[type="date"], input[type="text"]');
                }
            }

            if (!targetInput) {
                targetInput = document.getElementById('preferred_date') || document.querySelector('input[type="date"]');
            }

            window.openParishCalendarModal(targetInput);
        }
    });
})();
</script>
