/**
 * Admin Service Date Filter Component
 * San Lorenzo Ruiz Parish Management System (TUGON)
 * 
 * Interactive calendar popup with request count badges, keyboard navigation,
 * quick date chips, and dynamic category awareness.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', initServiceDateFilter);

    function initServiceDateFilter() {
        const wrap = document.getElementById('serviceDateWrap');
        const input = document.getElementById('serviceDateInput');
        const clearBtn = document.getElementById('serviceDateClearBtn');
        const helperText = document.getElementById('serviceDateHelper');
        const typeFilter = document.getElementById('requestTypeFilter');
        const statusFilter = document.getElementById('requestStatusFilter');
        const filterForm = document.getElementById('requestsFilterForm');
        const chips = document.querySelectorAll('.service-date-chip');

        if (!wrap || !input) return;

        let popup = null;
        let currentYearMonth = getInitialYearMonth();
        let focusedDate = null;
        let monthCounts = {};

        function getInitialYearMonth() {
            const val = input.value.trim();
            if (/^\d{4}-\d{2}-\d{2}$/.test(val)) {
                return val.substring(0, 7);
            }
            // Manila local date
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            return `${year}-${month}`;
        }

        // Toggle / Open Popup
        input.addEventListener('click', function (e) {
            if (input.disabled) return;
            e.stopPropagation();
            togglePopup();
        });

        input.addEventListener('keydown', function (e) {
            if (input.disabled) return;
            if (e.key === 'ArrowDown' || e.key === 'Enter') {
                if (!popup || popup.style.display === 'none') {
                    e.preventDefault();
                    openPopup();
                }
            } else if (e.key === 'Escape') {
                closePopup();
            }
        });

        // Clear (x) button
        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                e.preventDefault();
                input.value = '';
                clearBtn.style.display = 'none';
                chips.forEach(c => c.classList.remove('active'));
                closePopup();
                if (filterForm) {
                    filterForm.submit();
                }
            });
        }

        // Quick Chips ("Today", "Tomorrow", "This week")
        chips.forEach(chip => {
            chip.addEventListener('click', function (e) {
                if (input.disabled) return;
                e.preventDefault();
                const targetDate = chip.getAttribute('data-date');
                if (targetDate) {
                    input.value = targetDate;
                    chips.forEach(c => c.classList.remove('active'));
                    chip.classList.add('active');
                    if (clearBtn) clearBtn.style.display = 'inline-flex';
                    closePopup();
                    if (filterForm) {
                        filterForm.submit();
                    }
                }
            });
        });

        // Category changes: disable when Certificate
        if (typeFilter) {
            typeFilter.addEventListener('change', function () {
                const isCert = typeFilter.value === 'certificate';
                if (isCert) {
                    input.disabled = true;
                    input.value = '';
                    if (clearBtn) clearBtn.style.display = 'none';
                    if (helperText) {
                        helperText.textContent = 'Not applicable to certificates';
                    }
                    chips.forEach(c => {
                        c.disabled = true;
                        c.style.opacity = '0.5';
                        c.style.pointerEvents = 'none';
                    });
                    closePopup();
                } else {
                    input.disabled = false;
                    if (helperText) {
                        helperText.textContent = 'Day the parishioner wants the blessing or service.';
                    }
                    chips.forEach(c => {
                        c.disabled = false;
                        c.style.opacity = '1';
                        c.style.pointerEvents = 'auto';
                    });
                }
            });
        }

        // Close when clicking outside
        document.addEventListener('click', function (e) {
            if (popup && !popup.contains(e.target) && e.target !== input) {
                closePopup();
            }
        });

        function togglePopup() {
            if (popup && popup.style.display !== 'none') {
                closePopup();
            } else {
                openPopup();
            }
        }

        function openPopup() {
            if (input.disabled) return;
            if (!popup) {
                createPopup();
            }
            popup.style.display = 'block';
            fetchCountsAndRender();
        }

        function closePopup() {
            if (popup) {
                popup.style.display = 'none';
            }
        }

        function createPopup() {
            popup = document.createElement('div');
            popup.id = 'serviceDatePopup';
            popup.className = 'pds-cal-popup';
            popup.setAttribute('role', 'dialog');
            popup.setAttribute('aria-label', 'Service date calendar picker');
            popup.setAttribute('tabindex', '-1');

            wrap.appendChild(popup);

            // Handle popup keyboard events
            popup.addEventListener('keydown', handleKeyboardNavigation);
        }

        function fetchCountsAndRender() {
            const category = typeFilter ? typeFilter.value : '';
            const status = statusFilter ? statusFilter.value : '';

            renderCalendarGrid(true);

            const url = `../api/admin/requests/service-date-counts.php?month=${currentYearMonth}&category=${encodeURIComponent(category)}&status=${encodeURIComponent(status)}`;

            fetch(url, {
                headers: { 'Cache-Control': 'no-cache' }
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success && data.counts) {
                    monthCounts = data.counts;
                } else {
                    monthCounts = {};
                }
                renderCalendarGrid(false);
            })
            .catch(() => {
                monthCounts = {};
                renderCalendarGrid(false);
            });
        }

        function renderCalendarGrid(loading) {
            if (!popup) return;

            const [yearStr, monthStr] = currentYearMonth.split('-');
            const year = parseInt(yearStr, 10);
            const month = parseInt(monthStr, 10) - 1; // 0-indexed

            const monthNames = [
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'
            ];

            const firstDay = new Date(year, month, 1).getDay(); // 0 (Sun) to 6 (Sat)
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            const selectedVal = input.value.trim();

            const today = new Date();
            const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

            let html = `
                <div class="pds-cal-header">
                    <button type="button" class="pds-cal-nav-btn pds-cal-prev" aria-label="Previous month">
                        <i class="fas fa-chevron-left" aria-hidden="true"></i>
                    </button>
                    <div class="pds-cal-title" aria-live="polite">
                        ${monthNames[month]} ${year}
                    </div>
                    <button type="button" class="pds-cal-nav-btn pds-cal-next" aria-label="Next month">
                        <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="pds-cal-weekdays" aria-hidden="true">
                    <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                </div>
                <div class="pds-cal-grid" role="grid">
            `;

            // Leading empty padding
            for (let i = 0; i < firstDay; i++) {
                html += '<div class="pds-cal-day pds-empty" aria-hidden="true"></div>';
            }

            // Days of the month
            for (let d = 1; d <= daysInMonth; d++) {
                const dayStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const count = monthCounts[dayStr] || 0;
                const isSelected = selectedVal === dayStr;
                const isToday = todayStr === dayStr;

                let classes = ['pds-cal-day'];
                if (isSelected) classes.push('pds-selected');
                if (isToday) classes.push('pds-today');

                let badgeHtml = '';
                if (count > 0) {
                    badgeHtml = `<span class="pds-cal-badge" aria-label="${count} request${count > 1 ? 's' : ''}">${count}</span>`;
                }

                html += `
                    <button type="button" 
                            class="${classes.join(' ')}" 
                            data-date="${dayStr}" 
                            data-day="${d}"
                            role="gridcell" 
                            aria-selected="${isSelected ? 'true' : 'false'}"
                            aria-label="${d} ${monthNames[month]} ${year}${count > 0 ? ', ' + count + ' requests' : ''}">
                        ${d}
                        ${badgeHtml}
                    </button>
                `;
            }

            html += '</div>';

            popup.innerHTML = html;

            // Attach navigation listeners
            const prevBtn = popup.querySelector('.pds-cal-prev');
            const nextBtn = popup.querySelector('.pds-cal-next');

            if (prevBtn) {
                prevBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    navigateMonth(-1);
                });
            }

            if (nextBtn) {
                nextBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    navigateMonth(1);
                });
            }

            // Attach day click listeners
            const dayBtns = popup.querySelectorAll('.pds-cal-day:not(.pds-empty)');
            dayBtns.forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const chosen = btn.getAttribute('data-date');
                    if (chosen) {
                        selectDate(chosen);
                    }
                });
            });

            // Set initial keyboard focus
            const initialFocusTarget = popup.querySelector('.pds-selected') || popup.querySelector('.pds-today') || dayBtns[0];
            if (initialFocusTarget) {
                focusedDate = initialFocusTarget.getAttribute('data-date');
                initialFocusTarget.focus();
            }
        }

        function navigateMonth(direction) {
            const [y, m] = currentYearMonth.split('-').map(Number);
            let newYear = y;
            let newMonth = m + direction;

            if (newMonth < 1) {
                newMonth = 12;
                newYear--;
            } else if (newMonth > 12) {
                newMonth = 1;
                newYear++;
            }

            currentYearMonth = `${newYear}-${String(newMonth).padStart(2, '0')}`;
            fetchCountsAndRender();
        }

        function selectDate(dateStr) {
            input.value = dateStr;
            if (clearBtn) clearBtn.style.display = 'inline-flex';
            chips.forEach(c => {
                c.classList.toggle('active', c.getAttribute('data-date') === dateStr);
            });
            closePopup();
            input.focus();

            // Instant apply
            if (filterForm) {
                filterForm.submit();
            }
        }

        function handleKeyboardNavigation(e) {
            const dayBtns = Array.from(popup.querySelectorAll('.pds-cal-day:not(.pds-empty)'));
            if (!dayBtns.length) return;

            let currentIdx = dayBtns.findIndex(b => b.getAttribute('data-date') === focusedDate);
            if (currentIdx === -1) currentIdx = 0;

            let targetIdx = null;

            switch (e.key) {
                case 'ArrowLeft':
                    e.preventDefault();
                    targetIdx = currentIdx - 1;
                    if (targetIdx < 0) {
                        navigateMonth(-1);
                        return;
                    }
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    targetIdx = currentIdx + 1;
                    if (targetIdx >= dayBtns.length) {
                        navigateMonth(1);
                        return;
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    targetIdx = currentIdx - 7;
                    if (targetIdx < 0) {
                        navigateMonth(-1);
                        return;
                    }
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    targetIdx = currentIdx + 7;
                    if (targetIdx >= dayBtns.length) {
                        navigateMonth(1);
                        return;
                    }
                    break;
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    if (currentIdx >= 0 && dayBtns[currentIdx]) {
                        selectDate(dayBtns[currentIdx].getAttribute('data-date'));
                    }
                    return;
                case 'Escape':
                    e.preventDefault();
                    closePopup();
                    input.focus();
                    return;
                case 'Tab':
                    closePopup();
                    return;
                default:
                    return;
            }

            if (targetIdx !== null && dayBtns[targetIdx]) {
                focusedDate = dayBtns[targetIdx].getAttribute('data-date');
                dayBtns[targetIdx].focus();
            }
        }
    }
})();
