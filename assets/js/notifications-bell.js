/**
 * Parish Secretary & Admin Notification Bell Client
 * -------------------------------------------------
 * Handles:
 * 1. 30-second live polling to api/notifications-bell.php
 * 2. Visual urgency color coding (Today solid red, Tomorrow red, 2 days orange, 3 days gold)
 * 3. Unread gold dot indicator
 * 4. Bell shake animation and floating toast notification when new reminders arrive online
 * 5. Instant "Mark as read" per item and "Mark all as read"
 * 6. Responsive mobile and dark mode compatibility
 */

(function () {
    'use strict';

    var pollIntervalMs = 30000; // 30 seconds
    var lastKnownMaxId = 0;
    var isPolling = false;

    // Resolve API URL
    var baseUrl = (typeof window.BASE_URL !== 'undefined') ? window.BASE_URL : '/';
    if (!baseUrl.endsWith('/')) baseUrl += '/';
    var apiUrl = baseUrl + 'api/notifications-bell.php';

    // Elements
    var bellBtn = document.getElementById('adminNotifDropdownBtn');
    var badge = document.getElementById('adminUnreadBadge');
    var headerCount = document.getElementById('adminUnreadHeaderCount');
    var schedListEl = document.getElementById('scheduleRemindersList');
    var otherListEl = document.getElementById('otherUpdatesList');
    var schedCountEl = document.getElementById('countScheduleReminders');
    var otherCountEl = document.getElementById('countOtherUpdates');
    var btnMarkAllRead = document.getElementById('btnMarkAllRead');

    if (!bellBtn && !badge) {
        return; // Not in admin/secretary header
    }

    // Initialize Last Max ID from initial server render if available
    var initialMaxIdEl = document.getElementById('adminInitialLatestId');
    if (initialMaxIdEl) {
        lastKnownMaxId = parseInt(initialMaxIdEl.value, 10) || 0;
    }

    /**
     * Trigger bell shaking animation
     */
    function triggerBellShake() {
        if (!bellBtn) return;
        bellBtn.classList.add('bell-shaking');
        setTimeout(function () {
            bellBtn.classList.remove('bell-shaking');
        }, 900);
    }

    /**
     * Show modern floating toast for new incoming reminders
     */
    function showNotificationToast(title, message, urgencyColor, actionUrl) {
        var container = document.getElementById('parishNotifToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'parishNotifToastContainer';
            container.className = 'position-fixed bottom-0 end-0 p-3';
            container.style.zIndex = '9999';
            container.style.maxWidth = '360px';
            document.body.appendChild(container);
        }

        var toastId = 'toast_' + Date.now();
        var edge = urgencyColor || '#C89B3C';
        var toastHtml = `
            <div id="${toastId}" class="toast show shadow-lg border-0 mb-2 rounded-3" role="alert" aria-live="assertive" aria-atomic="true" style="border-left: 5px solid ${edge} !important; background: #FFFFFF; font-family: 'Inter', system-ui, sans-serif;">
                <div class="toast-header bg-white border-bottom py-2 px-3 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 22px; height: 22px; background: ${edge}; font-size: 11px;">
                            <i class="fas fa-bell"></i>
                        </span>
                        <strong class="me-auto text-dark" style="font-size: 12.5px;">Schedule Reminder</strong>
                    </div>
                    <small class="text-muted" style="font-size: 11px;">Just now</small>
                    <button type="button" class="btn-close ms-2" style="font-size: 10px;" onclick="document.getElementById('${toastId}').remove()"></button>
                </div>
                <div class="toast-body p-3">
                    <strong class="d-block text-dark mb-1" style="font-size: 13px;">${escapeHtml(title)}</strong>
                    <p class="text-muted mb-2" style="font-size: 12px; line-height: 1.4;">${escapeHtml(message)}</p>
                    <div class="text-end">
                        <a href="${actionUrl || '#'}" class="btn btn-sm btn-dark py-1 px-3 fw-bold" style="font-size: 11px; background: #1e2d24; border-color: #1e2d24;">View Details &rarr;</a>
                    </div>
                </div>
            </div>
        `;

        var wrapper = document.createElement('div');
        wrapper.innerHTML = toastHtml.trim();
        var toastEl = wrapper.firstChild;
        container.appendChild(toastEl);

        // Auto remove after 8 seconds
        setTimeout(function () {
            if (toastEl && toastEl.parentNode) {
                toastEl.classList.remove('show');
                setTimeout(function () { if (toastEl.parentNode) toastEl.remove(); }, 300);
            }
        }, 8000);
    }

    /**
     * Escape HTML helper
     */
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Update unread badge counter
     */
    function updateBadgeCount(count) {
        count = parseInt(count, 10) || 0;
        if (badge) {
            if (count > 0) {
                badge.innerText = count > 99 ? '99+' : count;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        }
        if (headerCount) {
            if (count > 0) {
                headerCount.innerText = count + ' unread';
                headerCount.style.display = 'inline-block';
            } else {
                headerCount.style.display = 'none';
            }
        }
    }

    /**
     * Render Schedule Reminders list
     */
    function renderScheduleReminders(items) {
        if (!schedListEl) return;
        if (schedCountEl) schedCountEl.innerText = items.length;

        if (items.length === 0) {
            schedListEl.innerHTML = `
                <div class="text-center py-3 px-2 text-muted" style="font-size: 11.5px;">
                    <i class="far fa-calendar-check me-1 text-secondary"></i> No schedule reminders in next 3 days
                </div>
            `;
            return;
        }

        var html = '';
        items.forEach(function (item) {
            var isUnread = item.is_unread === 1;
            var edgeColor = item.edge_color || '#C89B3C';
            var rowBg = isUnread ? 'background: #FAF8F2;' : 'background: #FFFFFF;';

            html += `
                <div class="position-relative d-flex align-items-start gap-2 p-2 px-3 border-bottom notif-item-row" 
                     id="notif_row_${item.id}"
                     style="${rowBg} border-left: 4px solid ${edgeColor} !important; transition: background 0.15s ease;">
                    <a href="${item.action_url || '#'}" class="text-decoration-none flex-grow-1 overflow-hidden" style="color: inherit;">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <strong class="text-truncate d-block" style="font-size: 12.5px; color: #1E293B; max-width: 240px;">
                                ${escapeHtml(item.title)}
                            </strong>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                ${isUnread ? '<span class="unread-gold-dot" id="dot_' + item.id + '" title="Unread"></span>' : ''}
                            </div>
                        </div>
                        <p class="mb-1 text-muted text-truncate" style="font-size: 11.5px; line-height: 1.35;">
                            ${escapeHtml(item.message)}
                        </p>
                        <small class="text-muted" style="font-size: 10px;">
                            <i class="far fa-clock me-1"></i>${escapeHtml(item.time_ago)}
                        </small>
                    </a>
                    ${isUnread ? `
                        <button type="button" class="btn btn-sm text-muted p-0 flex-shrink-0 btn-mark-item-read" 
                                onclick="window.markParishNotificationRead(event, ${item.id})"
                                title="Mark as read" style="font-size: 12px; width: 22px; height: 22px; line-height: 22px; text-align: center;">
                            <i class="fas fa-check"></i>
                        </button>
                    ` : ''}
                </div>
            `;
        });
        schedListEl.innerHTML = html;
    }

    /**
     * Render Other Updates list
     */
    function renderOtherUpdates(items) {
        if (!otherListEl) return;
        if (otherCountEl) otherCountEl.innerText = items.length;

        if (items.length === 0) {
            otherListEl.innerHTML = `
                <div class="text-center py-3 px-2 text-muted" style="font-size: 11.5px;">
                    <i class="far fa-bell-slash me-1 text-secondary"></i> No other pending updates
                </div>
            `;
            return;
        }

        var html = '';
        items.forEach(function (item) {
            var isUnread = item.is_unread === 1;
            var iconClass = item.icon || 'fa-bell';
            var iconColor = item.icon_color || '#475569';
            var rowBg = isUnread ? 'background: #FAF8F2;' : 'background: #FFFFFF;';

            html += `
                <div class="position-relative d-flex align-items-start gap-2 p-2 px-3 border-bottom notif-item-row" 
                     id="notif_row_${item.id}"
                     style="${rowBg} transition: background 0.15s ease;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 mt-1" 
                         style="width: 26px; height: 26px; background: #F1F5F9; color: ${iconColor}; font-size: 11px;">
                        <i class="fas ${iconClass}"></i>
                    </div>
                    <a href="${item.action_url || '#'}" class="text-decoration-none flex-grow-1 overflow-hidden" style="color: inherit;">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <strong class="text-truncate d-block" style="font-size: 12px; color: #1E293B; max-width: 220px;">
                                ${escapeHtml(item.title)}
                            </strong>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                ${isUnread ? '<span class="unread-gold-dot" id="dot_' + item.id + '" title="Unread"></span>' : ''}
                            </div>
                        </div>
                        <p class="mb-1 text-muted text-truncate" style="font-size: 11px; line-height: 1.35;">
                            ${escapeHtml(item.message)}
                        </p>
                        <small class="text-muted" style="font-size: 10px;">
                            <i class="far fa-clock me-1"></i>${escapeHtml(item.time_ago)}
                        </small>
                    </a>
                    ${isUnread ? `
                        <button type="button" class="btn btn-sm text-muted p-0 flex-shrink-0 btn-mark-item-read" 
                                onclick="window.markParishNotificationRead(event, ${item.id})"
                                title="Mark as read" style="font-size: 12px; width: 22px; height: 22px; line-height: 22px; text-align: center;">
                            <i class="fas fa-check"></i>
                        </button>
                    ` : ''}
                </div>
            `;
        });
        otherListEl.innerHTML = html;
    }

    /**
     * Poll Notifications API
     */
    function pollNotifications() {
        if (isPolling) return;
        isPolling = true;

        var url = apiUrl + '?action=list&last_id=' + lastKnownMaxId + '&_t=' + Date.now();

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                isPolling = false;
                if (!data || !data.success) return;

                var unreadCount = parseInt(data.unread_count, 10) || 0;
                updateBadgeCount(unreadCount);

                // Render lists
                if (data.schedule_reminders) {
                    renderScheduleReminders(data.schedule_reminders);
                }
                if (data.other_updates) {
                    renderOtherUpdates(data.other_updates);
                }

                // Check for new arrivals while user is online
                if (data.has_new && data.latest_id > lastKnownMaxId) {
                    triggerBellShake();

                    // If schedule reminders arrived, toast the highest urgency one
                    if (data.schedule_reminders && data.schedule_reminders.length > 0) {
                        var first = data.schedule_reminders[0];
                        showNotificationToast(first.title, first.message, first.edge_color, first.action_url);
                    } else if (data.other_updates && data.other_updates.length > 0) {
                        var otherFirst = data.other_updates[0];
                        showNotificationToast(otherFirst.title, otherFirst.message, '#0F5132', otherFirst.action_url);
                    }
                }

                if (data.latest_id) {
                    lastKnownMaxId = Math.max(lastKnownMaxId, parseInt(data.latest_id, 10));
                }
            })
            .catch(function (err) {
                isPolling = false;
            });
    }

    /**
     * Mark single notification as read
     */
    window.markParishNotificationRead = function (evt, notifId) {
        if (evt) {
            evt.preventDefault();
            evt.stopPropagation();
        }

        // Optimistic UI update
        var dot = document.getElementById('dot_' + notifId);
        if (dot) dot.remove();
        var row = document.getElementById('notif_row_' + notifId);
        if (row) {
            row.style.background = '#FFFFFF';
            var btn = row.querySelector('.btn-mark-item-read');
            if (btn) btn.remove();
        }

        fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                action: 'mark_read',
                notification_id: notifId
            })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success) {
                    updateBadgeCount(data.unread_count);
                }
            })
            .catch(function () {});
    };

    /**
     * Mark all notifications as read
     */
    function markAllNotificationsRead(evt) {
        if (evt) {
            evt.preventDefault();
            evt.stopPropagation();
        }

        // Optimistic UI
        document.querySelectorAll('.unread-gold-dot').forEach(function (el) { el.remove(); });
        document.querySelectorAll('.btn-mark-item-read').forEach(function (el) { el.remove(); });
        document.querySelectorAll('.notif-item-row').forEach(function (el) { el.style.background = '#FFFFFF'; });
        updateBadgeCount(0);

        fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ action: 'mark_all_read' })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success) {
                    updateBadgeCount(0);
                }
            })
            .catch(function () {});
    }

    if (btnMarkAllRead) {
        btnMarkAllRead.addEventListener('click', markAllNotificationsRead);
    }

    // Refresh on dropdown click
    if (bellBtn) {
        bellBtn.addEventListener('click', function () {
            pollNotifications();
        });
    }

    // Start 30s background poll
    setInterval(pollNotifications, pollIntervalMs);
})();
