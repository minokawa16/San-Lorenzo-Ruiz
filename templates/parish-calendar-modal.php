<?php
/**
 * Parish Calendar Picker Modal Component ("Parish Calendar & Schedule")
 * Used across service and blessing request forms.
 * Allows parishioners to view upcoming parish schedules and pick an open date.
 */
?>
<!-- Parish Calendar Picker Modal -->
<div class="modal fade" id="parishCalendarPickerModal" tabindex="-1" aria-labelledby="parishCalendarModalLabel" aria-hidden="true" style="z-index: 1065;">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content parish-calendar-modal-content">
            <!-- Modal Header (High-contrast white text on dark green) -->
            <div class="modal-header parish-calendar-modal-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="parish-calendar-modal-emblem" aria-hidden="true">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div>
                        <h5 class="modal-title mb-0 fw-bold text-white" id="parishCalendarModalLabel">Parish Calendar &amp; Schedule</h5>
                        <small class="parish-calendar-modal-sub text-white">Check available dates and public parish services</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body parish-calendar-modal-body p-3 p-md-4">
                <!-- Toolbar: Month Nav & Today Jump -->
                <div class="parish-calendar-nav-bar">
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm parish-cal-nav-btn" id="parishCalPrevBtn" aria-label="Previous month" title="Previous month">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <h4 class="mb-0 fw-bold parish-cal-month-title" id="parishCalMonthTitle">October 2026</h4>
                        <button type="button" class="btn btn-sm parish-cal-nav-btn" id="parishCalNextBtn" aria-label="Next month" title="Next month">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm parish-cal-today-btn" id="parishCalTodayBtn">
                            <i class="fas fa-calendar-day me-1"></i> Today
                        </button>
                    </div>
                </div>

                <!-- Legend with Service Types -->
                <div class="parish-calendar-legend">
                    <span class="legend-item"><span class="legend-dot is-available"></span> Available</span>
                    <span class="legend-item"><span class="legend-dot is-today"></span> Today</span>
                    <span class="legend-item"><span class="legend-dot is-past"></span> Past</span>
                    <span class="legend-item"><span class="legend-chip-sample chip-baptism"></span> Baptism</span>
                    <span class="legend-item"><span class="legend-chip-sample chip-mass"></span> Mass</span>
                    <span class="legend-item"><span class="legend-chip-sample chip-blessing"></span> Blessing</span>
                    <span class="legend-item"><span class="legend-chip-sample chip-wedding"></span> Wedding</span>
                    <span class="legend-item"><span class="legend-chip-sample chip-funeral"></span> Funeral Mass</span>
                    <span class="legend-item"><span class="legend-chip-sample chip-confirmation"></span> Confirmation</span>
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

                <!-- Calendar Days Grid (96px desktop cells) -->
                <div class="parish-calendar-grid" id="parishCalGrid" role="grid" aria-label="Days of the month">
                    <!-- Populated by JavaScript -->
                </div>

                <!-- Schedule Panel Below Calendar -->
                <div class="parish-cal-selection-card mt-3" id="parishCalSelectionCard">
                    <!-- Populated dynamically on date click -->
                    <div class="parish-cal-placeholder-panel">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="fas fa-calendar-check parish-cal-sel-icon"></i>
                            <div>
                                <strong class="d-block text-dark parish-cal-panel-title" id="parishCalSelectedDateText">No date selected yet</strong>
                                <span class="small text-muted" id="parishCalSelectedDetailText">Click an upcoming date in the calendar above to view its schedule and select it.</span>
                            </div>
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

<style>
/* Parish Calendar Modal Core Styling */
.parish-calendar-modal-content {
    border-radius: 14px !important;
    overflow: hidden !important;
    border: 1px solid #E5DFD3 !important;
    box-shadow: 0 20px 50px rgba(34, 45, 36, 0.25) !important;
    background: #FAF7F0 !important;
}

/* Header: Dark Green with Crisp Pure White Text */
.parish-calendar-modal-header,
#parishCalendarPickerModal .modal-header.parish-calendar-modal-header {
    background: linear-gradient(135deg, #1b4332 0%, #264e3b 50%, #153828 100%) !important;
    color: #FFFFFF !important;
    border-bottom: 2px solid #C89B3C !important;
    padding: 14px 20px !important;
}

.parish-calendar-modal-header .modal-title,
#parishCalendarPickerModal .modal-title,
#parishCalendarPickerModal .parish-calendar-modal-header h5 {
    color: #FFFFFF !important;
    font-size: 1.15rem !important;
    font-weight: 700 !important;
    letter-spacing: 0.2px !important;
    margin: 0 !important;
}

.parish-calendar-modal-header .parish-calendar-modal-sub,
#parishCalendarPickerModal .parish-calendar-modal-sub,
#parishCalendarPickerModal .parish-calendar-modal-header small {
    color: #FFFFFF !important;
    opacity: 0.92 !important;
    font-size: 0.8rem !important;
    display: block !important;
    margin-top: 2px !important;
}

.parish-calendar-modal-emblem {
    width: 38px !important;
    height: 38px !important;
    border-radius: 50% !important;
    background: radial-gradient(circle at 35% 35%, #F7DC8A 0%, #DEB042 55%, #B8861B 100%) !important;
    border: 1.5px solid #ECC868 !important;
    color: #1F2920 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 1rem !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25) !important;
    flex-shrink: 0 !important;
}

.parish-calendar-modal-body {
    background: #FAF7F0 !important;
}

/* Toolbar */
.parish-calendar-nav-bar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    margin-bottom: 12px !important;
    background: #FFFFFF !important;
    padding: 8px 14px !important;
    border-radius: 10px !important;
    border: 1px solid #E5DFD3 !important;
}

.parish-cal-month-title {
    font-family: "Playfair Display", Georgia, serif !important;
    font-size: 1.15rem !important;
    color: #1b4332 !important;
    min-width: 170px !important;
    text-align: center !important;
}

.parish-cal-nav-btn {
    width: 32px !important;
    height: 32px !important;
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 6px !important;
    border: 1px solid #E5DFD3 !important;
    background: #F8F5ED !important;
    color: #1b4332 !important;
    transition: all 0.15s ease !important;
}

.parish-cal-nav-btn:hover {
    background: #1b4332 !important;
    color: #FFFFFF !important;
    border-color: #1b4332 !important;
}

.parish-cal-today-btn {
    background: #F8F5ED !important;
    border: 1px solid #C89B3C !important;
    color: #80611B !important;
    font-weight: 600 !important;
    font-size: 0.78rem !important;
    border-radius: 6px !important;
    padding: 4px 10px !important;
    transition: all 0.15s ease !important;
}

.parish-cal-today-btn:hover {
    background: #C89B3C !important;
    color: #FFFFFF !important;
}

/* Legend */
.parish-calendar-legend {
    display: flex !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    gap: 10px 14px !important;
    font-size: 0.73rem !important;
    color: #555e54 !important;
    margin-bottom: 10px !important;
    padding: 4px 2px !important;
}

.legend-item {
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    white-space: nowrap !important;
}

.legend-dot {
    width: 8px !important;
    height: 8px !important;
    border-radius: 50% !important;
    display: inline-block !important;
    flex-shrink: 0 !important;
}

.legend-dot.is-available { background: #22C55E !important; }
.legend-dot.is-today { background: #C89B3C !important; border: 1px solid #80611B !important; }
.legend-dot.is-past { background: #CBD5E1 !important; }

.legend-chip-sample {
    width: 8px !important;
    height: 8px !important;
    border-radius: 2px !important;
    display: inline-block !important;
    flex-shrink: 0 !important;
}
.legend-chip-sample.chip-baptism { background: #0284c7 !important; }
.legend-chip-sample.chip-mass { background: #d97706 !important; }
.legend-chip-sample.chip-blessing { background: #059669 !important; }
.legend-chip-sample.chip-wedding { background: #7c3aed !important; }
.legend-chip-sample.chip-funeral { background: #4b5563 !important; }
.legend-chip-sample.chip-confirmation { background: #dc2626 !important; }

/* Weekdays */
.parish-calendar-weekdays {
    display: grid !important;
    grid-template-columns: repeat(7, 1fr) !important;
    gap: 6px !important;
    text-align: center !important;
    font-size: 0.74rem !important;
    font-weight: 700 !important;
    color: #80611B !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin-bottom: 6px !important;
}

/* Grid & Date Cells (Taller ~96px on Desktop) */
.parish-calendar-grid {
    display: grid !important;
    grid-template-columns: repeat(7, 1fr) !important;
    gap: 6px !important;
}

.parish-cal-day {
    min-height: 96px !important;
    height: 96px !important;
    border: 1px solid #E5DFD3 !important;
    border-radius: 8px !important;
    background: #FFFFFF !important;
    color: #2E3A2D !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: stretch !important;
    justify-content: flex-start !important;
    padding: 5px 6px !important;
    cursor: pointer !important;
    position: relative !important;
    overflow: hidden !important;
    transition: all 0.16s ease !important;
    text-align: left !important;
    box-sizing: border-box !important;
}

.parish-cal-day .day-num {
    font-size: 0.82rem !important;
    font-weight: 700 !important;
    color: #2E3A2D !important;
    line-height: 1 !important;
    margin-bottom: 4px !important;
    display: inline-block !important;
}

.parish-cal-day.is-outside {
    background: transparent !important;
    border-color: transparent !important;
    color: #C5BFB3 !important;
    cursor: default !important;
    opacity: 0.4 !important;
    pointer-events: none !important;
}

.parish-cal-day.is-disabled {
    background: #F3EFE6 !important;
    color: #B5AFA4 !important;
    border-color: #EAE5DB !important;
    cursor: not-allowed !important;
    opacity: 0.45 !important;
    pointer-events: none !important;
}

.parish-cal-day.is-disabled .day-num {
    color: #B5AFA4 !important;
}

/* Today: Gold Border */
.parish-cal-day.is-today {
    border: 2px solid #C89B3C !important;
    box-shadow: 0 0 0 1px rgba(200, 155, 60, 0.35) !important;
}

.parish-cal-day.is-today .day-num {
    color: #80611B !important;
}

/* Hover on Available */
.parish-cal-day.is-available:hover:not(:disabled) {
    background: #F4EFE3 !important;
    border-color: #C89B3C !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 10px rgba(200, 155, 60, 0.2) !important;
}

/* Selected Date: Dark Green Background with Gold Border */
.parish-cal-day.is-selected {
    background: linear-gradient(135deg, #1b4332 0%, #153728 100%) !important;
    color: #FFFFFF !important;
    border: 2px solid #C89B3C !important;
    box-shadow: 0 4px 12px rgba(27, 67, 50, 0.35) !important;
}

.parish-cal-day.is-selected .day-num {
    color: #FFFFFF !important;
}

/* In-Cell Schedule Chips Container */
.parish-cal-chips {
    display: flex !important;
    flex-direction: column !important;
    gap: 2.5px !important;
    width: 100% !important;
    overflow: hidden !important;
}

/* Chips: Format "<time> · <service>" with bold time & ellipsis */
.parish-cal-chip {
    display: block !important;
    font-size: 0.67rem !important;
    line-height: 1.25 !important;
    padding: 2px 4px !important;
    border-radius: 3px !important;
    border-left-width: 3px !important;
    border-left-style: solid !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    transition: background-color 0.15s ease, color 0.15s ease !important;
}

.parish-cal-chip strong,
.parish-cal-chip .chip-time {
    font-weight: 700 !important;
}

.parish-cal-chip span,
.parish-cal-chip .chip-service {
    font-weight: 500 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* Service Color-Coding (Colored left edge and light tint) */
.service-chip-baptism {
    border-left-color: #0284c7 !important;
    background-color: #e0f2fe !important;
    color: #0369a1 !important;
}
.service-chip-baptism strong { color: #0c4a6e !important; }

.service-chip-mass {
    border-left-color: #d97706 !important;
    background-color: #fef3c7 !important;
    color: #92400e !important;
}
.service-chip-mass strong { color: #78350f !important; }

.service-chip-blessing {
    border-left-color: #059669 !important;
    background-color: #d1fae5 !important;
    color: #065f46 !important;
}
.service-chip-blessing strong { color: #064e3b !important; }

.service-chip-wedding {
    border-left-color: #7c3aed !important;
    background-color: #ede9fe !important;
    color: #5b21b6 !important;
}
.service-chip-wedding strong { color: #4c1d95 !important; }

.service-chip-funeral {
    border-left-color: #4b5563 !important;
    background-color: #f3f4f6 !important;
    color: #1f2937 !important;
}
.service-chip-funeral strong { color: #111827 !important; }

.service-chip-confirmation {
    border-left-color: #dc2626 !important;
    background-color: #fee2e2 !important;
    color: #991b1b !important;
}
.service-chip-confirmation strong { color: #7f1d1d !important; }

.service-chip-event {
    border-left-color: #0d9488 !important;
    background-color: #ccfbf1 !important;
    color: #115e59 !important;
}
.service-chip-event strong { color: #134e4a !important; }

/* +N more chip */
.parish-cal-more {
    display: inline-block !important;
    font-size: 0.64rem !important;
    font-weight: 700 !important;
    color: #475569 !important;
    background: #f1f5f9 !important;
    padding: 1px 4px !important;
    border-radius: 3px !important;
    width: fit-content !important;
    line-height: 1.2 !important;
}

/* SELECTED CELL CHIPS: Switch to light translucent background with white text */
.parish-cal-day.is-selected .parish-cal-chip {
    background-color: rgba(255, 255, 255, 0.22) !important;
    color: #FFFFFF !important;
}

.parish-cal-day.is-selected .parish-cal-chip strong,
.parish-cal-day.is-selected .parish-cal-chip span,
.parish-cal-day.is-selected .parish-cal-chip .chip-time,
.parish-cal-day.is-selected .parish-cal-chip .chip-service {
    color: #FFFFFF !important;
}

.parish-cal-day.is-selected .parish-cal-more {
    background-color: rgba(255, 255, 255, 0.28) !important;
    color: #FFFFFF !important;
}

/* Mobile Colored Dots Container */
.parish-cal-dots-container {
    display: none !important;
}

.parish-cal-dot {
    width: 6px !important;
    height: 6px !important;
    border-radius: 50% !important;
    display: inline-block !important;
    flex-shrink: 0 !important;
}

.service-dot-baptism { background-color: #0284c7 !important; }
.service-dot-mass { background-color: #d97706 !important; }
.service-dot-blessing { background-color: #059669 !important; }
.service-dot-wedding { background-color: #7c3aed !important; }
.service-dot-funeral { background-color: #4b5563 !important; }
.service-dot-confirmation { background-color: #dc2626 !important; }
.service-dot-event { background-color: #0d9488 !important; }

/* Responsive adjustments for mobile (under 600px) */
@media (max-width: 600px) {
    .parish-cal-day {
        min-height: 48px !important;
        height: 48px !important;
        padding: 3px 2px !important;
        align-items: center !important;
        justify-content: center !important;
    }
    .parish-cal-day .day-num {
        text-align: center !important;
        margin-bottom: 2px !important;
        font-size: 0.8rem !important;
    }
    .parish-cal-chips {
        display: none !important;
    }
    .parish-cal-dots-container {
        display: flex !important;
        gap: 3px !important;
        justify-content: center !important;
        align-items: center !important;
        margin-top: 1px !important;
        flex-wrap: wrap !important;
        max-width: 100% !important;
    }
    .parish-cal-month-title {
        font-size: 1rem !important;
        min-width: 130px !important;
    }
    .parish-calendar-legend {
        font-size: 0.68rem !important;
        gap: 6px 10px !important;
    }
}

/* Schedule Panel Below Calendar */
.parish-cal-selection-card {
    background: #FFFFFF !important;
    border: 1px solid #E5DFD3 !important;
    border-left: 4px solid #C89B3C !important;
    border-radius: 10px !important;
    padding: 14px 16px !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04) !important;
    transition: all 0.2s ease !important;
}

.parish-cal-sel-icon {
    color: #C89B3C !important;
    font-size: 1.25rem !important;
    margin-top: 2px !important;
}

.parish-cal-panel-heading {
    font-size: 1.05rem !important;
    font-weight: 700 !important;
    color: #1b4332 !important;
    border-bottom: 1px solid #f0eae1 !important;
    padding-bottom: 8px !important;
    margin-bottom: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: wrap !important;
    gap: 8px !important;
}

.parish-cal-free-notice {
    background: #f0fdf4 !important;
    border: 1px solid rgba(34, 197, 94, 0.3) !important;
    color: #166534 !important;
    border-radius: 8px !important;
    padding: 12px 14px !important;
}

.parish-cal-schedule-row {
    background: #faf8f5 !important;
    border: 1px solid #eedfc9 !important;
    border-radius: 8px !important;
    padding: 8px 12px !important;
    transition: background-color 0.15s ease !important;
}

.parish-cal-schedule-row:hover {
    background: #f4ede1 !important;
}

.parish-badge-type {
    display: inline-block !important;
    font-size: 0.72rem !important;
    font-weight: 700 !important;
    padding: 2px 8px !important;
    border-radius: 999px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.4px !important;
}

.badge-baptism { background: #e0f2fe !important; color: #0369a1 !important; border: 1px solid rgba(2, 132, 199, 0.3) !important; }
.badge-mass { background: #fef3c7 !important; color: #92400e !important; border: 1px solid rgba(217, 119, 6, 0.3) !important; }
.badge-blessing { background: #d1fae5 !important; color: #065f46 !important; border: 1px solid rgba(5, 150, 105, 0.3) !important; }
.badge-wedding { background: #ede9fe !important; color: #5b21b6 !important; border: 1px solid rgba(124, 58, 237, 0.3) !important; }
.badge-funeral { background: #f3f4f6 !important; color: #1f2937 !important; border: 1px solid rgba(75, 85, 99, 0.3) !important; }
.badge-confirmation { background: #fee2e2 !important; color: #991b1b !important; border: 1px solid rgba(220, 38, 38, 0.3) !important; }
.badge-event { background: #ccfbf1 !important; color: #115e59 !important; border: 1px solid rgba(13, 148, 136, 0.3) !important; }

/* Modal Footer & Action Button */
.parish-calendar-modal-footer {
    background: #FAF7F0 !important;
    border-top: 1px solid #E5DFD3 !important;
    padding: 12px 20px !important;
}

.btn-parish-apply-date {
    background: linear-gradient(180deg, #1b4332 0%, #153828 100%) !important;
    color: #FFFDF8 !important;
    border: 1px solid rgba(200, 155, 60, 0.5) !important;
    border-bottom: 2.5px solid #C89B3C !important;
    font-weight: 700 !important;
    border-radius: 6px !important;
    transition: all 0.16s ease !important;
}

.btn-parish-apply-date:hover:not(:disabled) {
    background: #25523d !important;
    color: #FFFFFF !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 10px rgba(27, 67, 50, 0.3) !important;
}

.btn-parish-apply-date:disabled {
    opacity: 0.5 !important;
    cursor: not-allowed !important;
}

/* ========================================================
   DARK MODE SUPPORT
   ======================================================== */
[data-theme="dark"] .parish-calendar-modal-content,
body[data-theme="dark"] .parish-calendar-modal-content {
    background: #182026 !important;
    border-color: rgba(200, 155, 60, 0.25) !important;
    color: #e2e8f0 !important;
}

[data-theme="dark"] .parish-calendar-modal-body,
body[data-theme="dark"] .parish-calendar-modal-body,
[data-theme="dark"] .parish-calendar-modal-footer,
body[data-theme="dark"] .parish-calendar-modal-footer {
    background: #182026 !important;
    border-color: rgba(255, 255, 255, 0.08) !important;
}

[data-theme="dark"] .parish-calendar-nav-bar,
body[data-theme="dark"] .parish-calendar-nav-bar {
    background: #222c36 !important;
    border-color: rgba(255, 255, 255, 0.08) !important;
}

[data-theme="dark"] .parish-cal-month-title,
body[data-theme="dark"] .parish-cal-month-title {
    color: #f8f6f1 !important;
}

[data-theme="dark"] .parish-cal-nav-btn,
body[data-theme="dark"] .parish-cal-nav-btn {
    background: #2a3642 !important;
    border-color: rgba(255, 255, 255, 0.12) !important;
    color: #f1f5f9 !important;
}

[data-theme="dark"] .parish-cal-nav-btn:hover,
body[data-theme="dark"] .parish-cal-nav-btn:hover {
    background: #C89B3C !important;
    color: #1a2026 !important;
}

[data-theme="dark"] .parish-cal-today-btn,
body[data-theme="dark"] .parish-cal-today-btn {
    background: #222c36 !important;
    color: #ffd700 !important;
    border-color: #C89B3C !important;
}

[data-theme="dark"] .parish-calendar-legend,
body[data-theme="dark"] .parish-calendar-legend {
    color: #94a3b8 !important;
}

[data-theme="dark"] .parish-cal-day,
body[data-theme="dark"] .parish-cal-day {
    background: #222c36 !important;
    border-color: rgba(255, 255, 255, 0.08) !important;
    color: #e2e8f0 !important;
}

[data-theme="dark"] .parish-cal-day .day-num,
body[data-theme="dark"] .parish-cal-day .day-num {
    color: #e2e8f0 !important;
}

[data-theme="dark"] .parish-cal-day.is-disabled,
body[data-theme="dark"] .parish-cal-day.is-disabled {
    background: #192027 !important;
    border-color: transparent !important;
    opacity: 0.35 !important;
}

[data-theme="dark"] .parish-cal-day.is-disabled .day-num,
body[data-theme="dark"] .parish-cal-day.is-disabled .day-num {
    color: #64748b !important;
}

[data-theme="dark"] .parish-cal-day.is-today,
body[data-theme="dark"] .parish-cal-day.is-today {
    border-color: #C89B3C !important;
    box-shadow: 0 0 0 1px rgba(200, 155, 60, 0.45) !important;
}

[data-theme="dark"] .parish-cal-day.is-today .day-num,
body[data-theme="dark"] .parish-cal-day.is-today .day-num {
    color: #ffd700 !important;
}

[data-theme="dark"] .parish-cal-day.is-available:hover:not(:disabled),
body[data-theme="dark"] .parish-cal-day.is-available:hover:not(:disabled) {
    background: #2c3846 !important;
    border-color: #C89B3C !important;
}

[data-theme="dark"] .parish-cal-day.is-selected,
body[data-theme="dark"] .parish-cal-day.is-selected {
    background: linear-gradient(135deg, #166534 0%, #0f3d24 100%) !important;
    border-color: #ffd700 !important;
}

[data-theme="dark"] .parish-cal-day.is-selected .day-num,
body[data-theme="dark"] .parish-cal-day.is-selected .day-num {
    color: #ffffff !important;
}

[data-theme="dark"] .parish-cal-selection-card,
body[data-theme="dark"] .parish-cal-selection-card {
    background: #222c36 !important;
    border-color: rgba(255, 255, 255, 0.1) !important;
    border-left-color: #C89B3C !important;
    color: #e2e8f0 !important;
}

[data-theme="dark"] .parish-cal-panel-heading,
body[data-theme="dark"] .parish-cal-panel-heading {
    color: #ffd700 !important;
    border-color: rgba(255, 255, 255, 0.1) !important;
}

[data-theme="dark"] .parish-cal-schedule-row,
body[data-theme="dark"] .parish-cal-schedule-row {
    background: #1b232c !important;
    border-color: rgba(255, 255, 255, 0.08) !important;
    color: #f1f5f9 !important;
}

[data-theme="dark"] .parish-cal-schedule-row:hover,
body[data-theme="dark"] .parish-cal-schedule-row:hover {
    background: #273340 !important;
}

[data-theme="dark"] .parish-cal-schedule-row .parish-schedule-item-name,
body[data-theme="dark"] .parish-cal-schedule-row .parish-schedule-item-name {
    color: #f8fafc !important;
}

[data-theme="dark"] .parish-cal-free-notice,
body[data-theme="dark"] .parish-cal-free-notice {
    background: rgba(34, 197, 94, 0.12) !important;
    border-color: rgba(34, 197, 94, 0.25) !important;
    color: #4ade80 !important;
}

[data-theme="dark"] .parish-cal-more,
body[data-theme="dark"] .parish-cal-more {
    background: #334155 !important;
    color: #e2e8f0 !important;
}
</style>

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
    const selectionCardEl = document.getElementById('parishCalSelectionCard');

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

    function formatTime12(timeStr) {
        if (!timeStr) return '';
        const trimmed = String(timeStr).trim();
        // If already 12-hour e.g. "8:00 AM"
        if (/am|pm/i.test(trimmed)) {
            return trimmed;
        }
        // Match 24hr e.g. "08:00" or "14:30:00"
        const m = trimmed.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
        if (m) {
            let h = parseInt(m[1], 10);
            const min = m[2];
            const ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12;
            if (h === 0) h = 12;
            return h + ':' + min + ' ' + ampm;
        }
        return trimmed;
    }

    function normalizeServiceType(str) {
        const t = String(str || '').toLowerCase();
        if (t.includes('funeral') || t.includes('burial') || t.includes('libing') || t.includes('requiem')) {
            return 'Funeral Mass';
        }
        if (t.includes('wedding') || t.includes('marriage') || t.includes('kasal') || t.includes('matrimon')) {
            return 'Wedding';
        }
        if (t.includes('baptism') || t.includes('binyag') || t.includes('christen')) {
            return 'Baptism';
        }
        if (t.includes('confirm') || t.includes('kumpil')) {
            return 'Confirmation';
        }
        if (t.includes('blessing') || t.includes('basbas')) {
            return 'Blessing';
        }
        if (t.includes('mass') || t.includes('misa') || t.includes('eucharist') || t.includes('communion')) {
            return 'Mass';
        }
        return 'Event';
    }

    function getServiceCssKey(type) {
        switch (type) {
            case 'Baptism': return 'baptism';
            case 'Mass': return 'mass';
            case 'Blessing': return 'blessing';
            case 'Wedding': return 'wedding';
            case 'Funeral Mass': return 'funeral';
            case 'Confirmation': return 'confirmation';
            default: return 'event';
        }
    }

    function sanitizeServiceName(rawTitle, serviceType) {
        let clean = String(rawTitle || '').trim();
        // Strip requester's private name if appended with dash/colon
        if (clean.includes(' - ')) {
            const prefix = clean.split(' - ')[0].trim();
            if (prefix.length > 2) clean = prefix;
        } else if (clean.includes(' – ')) {
            const prefix = clean.split(' – ')[0].trim();
            if (prefix.length > 2) clean = prefix;
        }
        // Normalize common titles
        const lower = clean.toLowerCase();
        if (lower.includes('house blessing')) return 'House Blessing';
        if (lower.includes('car blessing') || lower.includes('vehicle blessing')) return 'Vehicle Blessing';
        if (lower.includes('business blessing') || lower.includes('office blessing')) return 'Business Blessing';
        if (lower.includes('other blessing') || lower.includes('special blessing')) return 'Special Blessing';
        if (lower.includes('baptism')) return 'Baptism';
        if (lower.includes('wedding') || lower.includes('marriage')) return 'Wedding';
        if (lower.includes('funeral') || lower.includes('burial')) return 'Funeral Mass';
        if (lower.includes('confirmation') || lower.includes('kumpil')) return 'Confirmation';
        if (lower.includes('sunday mass')) return 'Sunday Mass';
        if (lower.includes('mass')) return 'Mass';
        if (lower === 'reserved' || lower === 'occupied' || lower.length <= 2) return serviceType;
        return clean;
    }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function(s) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[s];
        });
    }

    function getCalendarApiUrl(startDate, endDate) {
        let base = '../api/calendar-events.php';
        if (typeof window.APP_URL !== 'undefined') {
            base = window.APP_URL.replace(/\/+$/, '') + '/api/calendar-events.php';
        } else if (!window.location.pathname.match(/\/(users|admin|auth)\//)) {
            base = 'api/calendar-events.php';
        }
        return base + '?start=' + encodeURIComponent(startDate) + '&end=' + encodeURIComponent(endDate);
    }

    // Load schedules for the visible month (one single query per month)
    async function loadMonthEvents(year, month) {
        eventsByDate = {};
        const startDate = formatIsoDate(year, month, 1);
        const lastDay = new Date(year, month + 1, 0).getDate();
        const endDate = formatIsoDate(year, month, lastDay);

        try {
            const url = getCalendarApiUrl(startDate, endDate);
            const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (res.ok) {
                const list = await res.json();
                if (Array.isArray(list)) {
                    list.forEach(function(ev) {
                        const dStr = (ev.start || ev.date || ev.event_date || '').substring(0, 10);
                        if (!dStr) return;

                        const ext = ev.extendedProps || {};
                        const rawStart = (ev.start && ev.start.length >= 16) ? ev.start.substring(11, 16) : (ext.start_time || '08:00');
                        const formattedTime = ev.time_formatted || ext.time_formatted || formatTime12(rawStart);
                        const serviceType = ev.service_type || ext.service_type || normalizeServiceType(ev.category || ext.category || ev.title);
                        const serviceName = ev.service_name || ext.service_name || sanitizeServiceName(ev.title, serviceType);

                        if (!eventsByDate[dStr]) {
                            eventsByDate[dStr] = [];
                        }

                        eventsByDate[dStr].push({
                            time: formattedTime,
                            timeRaw: rawStart,
                            type: serviceType,
                            service: serviceName,
                            title: serviceName
                        });
                    });

                    // Sort chronologically by time on each day
                    Object.keys(eventsByDate).forEach(function(dStr) {
                        eventsByDate[dStr].sort(function(a, b) {
                            return String(a.timeRaw || '').localeCompare(String(b.timeRaw || ''));
                        });
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

            // Header: day number
            let innerHtml = '<span class="day-num">' + day + '</span>';

            // Desktop Chips (up to 2 chips, format: "<time> · <service>", bold time, +N more)
            if (dayEvents.length > 0) {
                innerHtml += '<div class="parish-cal-chips">';
                const visibleEvents = dayEvents.slice(0, 2);
                visibleEvents.forEach(function(ev) {
                    const cssKey = getServiceCssKey(ev.type);
                    const chipLabel = escapeHtml(ev.time) + ' · ' + escapeHtml(ev.service);
                    innerHtml += '<div class="parish-cal-chip service-chip-' + cssKey + '" title="' + chipLabel + '">' +
                        '<strong class="chip-time">' + escapeHtml(ev.time) + '</strong> · ' +
                        '<span class="chip-service">' + escapeHtml(ev.service) + '</span>' +
                        '</div>';
                });

                if (dayEvents.length > 2) {
                    const moreCount = dayEvents.length - 2;
                    innerHtml += '<div class="parish-cal-more">+' + moreCount + ' more</div>';
                }
                innerHtml += '</div>';

                // Mobile Dots Container (under 600px)
                innerHtml += '<div class="parish-cal-dots-container" aria-hidden="true">';
                dayEvents.slice(0, 4).forEach(function(ev) {
                    const cssKey = getServiceCssKey(ev.type);
                    innerHtml += '<span class="parish-cal-dot service-dot-' + cssKey + '" title="' + escapeHtml(ev.service) + '"></span>';
                });
                innerHtml += '</div>';
            }

            cell.innerHTML = innerHtml;

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

        // 3. Next month leading days to complete 7-col grid
        const totalRendered = firstDayIndex + daysInMonth;
        const remainingCells = (7 - (totalRendered % 7)) % 7;
        for (let day = 1; day <= remainingCells; day++) {
            const cell = document.createElement('div');
            cell.className = 'parish-cal-day is-outside';
            cell.innerHTML = '<span class="day-num">' + day + '</span>';
            gridEl.appendChild(cell);
        }

        updateSchedulePanel();
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

        updateSchedulePanel();
    }

    // Schedule Panel below calendar: shows full date as heading, then each service with time, name and type.
    // If nothing scheduled: "Nothing scheduled. This day is free."
    function updateSchedulePanel() {
        if (!selectionCardEl) return;

        if (!selectedDateStr) {
            selectionCardEl.innerHTML =
                '<div class="parish-cal-placeholder-panel d-flex align-items-center gap-2.5">' +
                    '<i class="fas fa-calendar-check parish-cal-sel-icon"></i>' +
                    '<div>' +
                        '<strong class="d-block text-dark parish-cal-panel-title">No date selected yet</strong>' +
                        '<span class="small text-muted">Click an upcoming date in the calendar above to view its schedule and select it.</span>' +
                    '</div>' +
                '</div>';
            if (applyBtn) applyBtn.disabled = true;
            return;
        }

        const humanDate = formatHumanDate(selectedDateStr);
        const dayEvents = eventsByDate[selectedDateStr] || [];

        let html = '<div class="parish-cal-schedule-panel-content">';
        html += '<div class="parish-cal-panel-heading">' +
                    '<span><i class="far fa-calendar-check me-2 text-warning"></i>' + escapeHtml(humanDate) + '</span>' +
                    '<span class="badge bg-light text-dark border">' + dayEvents.length + ' service' + (dayEvents.length === 1 ? '' : 's') + '</span>' +
                '</div>';

        if (dayEvents.length === 0) {
            html += '<div class="parish-cal-free-notice d-flex align-items-center gap-2.5">' +
                        '<i class="fas fa-check-circle text-success fs-5"></i>' +
                        '<div>' +
                            '<strong class="d-block">Nothing scheduled. This day is free.</strong>' +
                            '<span class="small opacity-75">You can select this open date for your requested schedule.</span>' +
                        '</div>' +
                    '</div>';
        } else {
            html += '<div class="d-flex flex-column gap-2">';
            dayEvents.forEach(function(ev) {
                const cssKey = getServiceCssKey(ev.type);
                html += '<div class="parish-cal-schedule-row d-flex align-items-center justify-content-between flex-wrap gap-2">' +
                            '<div class="d-flex align-items-center gap-2 min-w-0">' +
                                '<span class="parish-badge-type badge-' + cssKey + '">' + escapeHtml(ev.type) + '</span>' +
                                '<span class="fw-bold parish-schedule-item-name text-truncate">' + escapeHtml(ev.service) + '</span>' +
                            '</div>' +
                            '<div class="parish-schedule-item-time text-nowrap ms-auto small">' +
                                '<i class="far fa-clock text-muted me-1"></i>' +
                                '<strong>' + escapeHtml(ev.time) + '</strong>' +
                            '</div>' +
                        '</div>';
            });
            html += '</div>';
        }

        html += '</div>';
        selectionCardEl.innerHTML = html;

        if (applyBtn) {
            applyBtn.disabled = false;
        }
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

    // Month navigation listeners
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

    // Delegated click handler for triggers
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
