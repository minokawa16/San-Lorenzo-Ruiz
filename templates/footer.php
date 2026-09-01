<?php
/**
 * Footer Template - Closes shared layouts and loads common scripts for user and admin pages.
 */
?>
    <?php if (isset($is_user_area) && $is_user_area): ?>
            </main>
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

    <?php
    $action_notifications = function_exists('consumeActionNotifications') ? consumeActionNotifications() : [];
    ?>
    <div class="parish-toast-container" id="parishToastContainer" aria-live="polite" aria-atomic="true"></div>
    <?php if (isset($is_user_area) && $is_user_area): ?>
    <style>
        :root {
            --tugon-bg: #F8F6F1;
            --tugon-bg-deep: #FCFBF8;
            --tugon-surface: #FFFFFF;
            --tugon-ocean: #2E3A2D;
            --tugon-link: #C89B3C;
            --tugon-teal: #C89B3C;
            --tugon-aqua: rgba(200, 155, 60, 0.12);
            --tugon-stone: #E8E1D5;
            --tugon-border: #E8E1D5;
            --tugon-text: #222222;
            --tugon-muted: #6B7280;
            --tugon-shadow: 0 16px 36px rgba(34, 34, 34, 0.08);
        }

        body.user-area,
        body.user-area .user-shell,
        body.user-area .user-main,
        body.user-area .user-content,
        body.user-area .page-content,
        body.user-area .tugon-ai-page {
            background:
                linear-gradient(180deg, #F8F6F1 0%, var(--tugon-bg) 46%, var(--tugon-bg-deep) 100%) !important;
            color: var(--tugon-text) !important;
            font-family: "Inter", "Segoe UI", Arial, sans-serif !important;
            font-size: 16px !important;
            line-height: 1.6 !important;
        }

        body.user-area h1,
        body.user-area .page-title,
        body.user-area .dashboard-hero h1,
        body.user-area .request-hero-main h1,
        body.user-area .certificate-hero-main h1,
        body.user-area .announcement-hero-main h1,
        body.user-area .notification-hero h1,
        body.user-area .calendar-user-title h1,
        body.user-area .tugon-ai-title-block h1 {
            color: var(--tugon-text) !important;
            font-family: "Inter", "Segoe UI", Arial, sans-serif !important;
            font-size: clamp(28px, 3vw, 36px) !important;
            line-height: 1.25 !important;
            font-weight: 700 !important;
            letter-spacing: 0 !important;
        }

        body.user-area h2,
        body.user-area .section-title,
        body.user-area .premium-panel-title,
        body.user-area .calendar-side-section h2,
        body.user-area .notification-group-title,
        body.user-area .request-form-header h2,
        body.user-area .tugon-ai-welcome h2 {
            color: var(--tugon-text) !important;
            font-family: "Inter", "Segoe UI", Arial, sans-serif !important;
            font-size: clamp(20px, 2vw, 24px) !important;
            line-height: 1.3 !important;
            font-weight: 700 !important;
        }

        body.user-area h3,
        body.user-area h4,
        body.user-area h5,
        body.user-area h6,
        body.user-area .card-title,
        body.user-area .notification-card h3,
        body.user-area .announcement-card h3,
        body.user-area .request-type-option h3 {
            color: var(--tugon-text) !important;
            font-family: "Inter", "Segoe UI", Arial, sans-serif !important;
            font-size: clamp(17px, 1.5vw, 20px) !important;
            line-height: 1.35 !important;
            font-weight: 600 !important;
        }

        body.user-area p,
        body.user-area li,
        body.user-area td,
        body.user-area .card-text,
        body.user-area .text-muted,
        body.user-area .dashboard-card p,
        body.user-area .request-card p,
        body.user-area .announcement-card p,
        body.user-area .notification-card p,
        body.user-area .tugon-ai-welcome p,
        body.user-area .tugon-ai-side li {
            color: var(--tugon-text) !important;
            font-size: 16px !important;
            line-height: 1.6 !important;
        }

        body.user-area small,
        body.user-area .small,
        body.user-area .form-text,
        body.user-area .section-note,
        body.user-area .helper-text,
        body.user-area .notification-meta,
        body.user-area .calendar-legend,
        body.user-area .premium-kpi-note,
        body.user-area .tugon-ai-status {
            color: var(--tugon-muted) !important;
            font-size: 14px !important;
            line-height: 1.5 !important;
        }

        body.user-area .user-sidebar {
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0)),
                #222222 !important;
            color: #FFFFFF !important;
            border-right: 1px solid rgba(255, 255, 255, 0.16) !important;
            box-shadow: 12px 0 28px rgba(46, 58, 45, 0.14) !important;
        }

        body.user-area .user-sidebar .sidebar-brand {
            background: rgba(255, 255, 255, 0.95) !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.18) !important;
            color: var(--tugon-text) !important;
        }

        body.user-area .user-sidebar .brand-logo,
        body.user-area .user-sidebar .pill-badge,
        body.user-area .user-sidebar .profile-dot {
            background: var(--tugon-stone) !important;
            color: var(--tugon-text) !important;
            border-color: rgba(8, 115, 154, 0.18) !important;
        }

        body.user-area .user-sidebar .brand-title,
        body.user-area .user-sidebar .brand-subtitle,
        body.user-area .user-sidebar .sidebar-toggle,
        body.user-area .user-sidebar .nav-link,
        body.user-area .user-sidebar .nav-toggle,
        body.user-area .user-sidebar .nav-submenu .sublink {
            color: rgba(255, 255, 255, 0.9) !important;
        }

        body.user-area .user-sidebar .brand-title {
            font-size: 16px !important;
            line-height: 1.35 !important;
            font-weight: 700 !important;
        }

        body.user-area .user-sidebar .brand-subtitle,
        body.user-area .user-sidebar .profile-mini {
            font-size: 14px !important;
            line-height: 1.4 !important;
        }

        body.user-area .user-sidebar .nav-link,
        body.user-area .user-sidebar .nav-toggle,
        body.user-area .user-sidebar .nav-submenu .sublink {
            min-height: 44px !important;
            font-size: 15px !important;
            line-height: 1.4 !important;
            font-weight: 500 !important;
        }

        body.user-area .user-sidebar .nav-section-label {
            color: rgba(255, 255, 255, 0.7) !important;
            font-size: 13px !important;
            line-height: 1.4 !important;
            letter-spacing: 0.04em !important;
            font-weight: 600 !important;
        }

        body.user-area .user-sidebar .nav-link:hover,
        body.user-area .user-sidebar .nav-link.active,
        body.user-area .user-sidebar .nav-collapsible.open .nav-toggle {
            background: rgba(255, 255, 255, 0.16) !important;
            color: #FFFFFF !important;
            box-shadow: inset 4px 0 0 var(--tugon-teal) !important;
        }

        body.user-area .user-topbar,
        body.user-area .premium-glass,
        body.user-area .topbar,
        body.user-area .card,
        body.user-area .premium-panel,
        body.user-area .dashboard-panel,
        body.user-area .dashboard-card,
        body.user-area .request-card,
        body.user-area .request-form-card,
        body.user-area .announcement-card,
        body.user-area .notification-card,
        body.user-area .calendar-panel,
        body.user-area .calendar-side-panel,
        body.user-area .tugon-ai-card,
        body.user-area .tugon-ai-welcome,
        body.user-area .tugon-ai-side,
        body.user-area .payment-card,
        body.user-area .reservation-card {
            background: var(--tugon-surface) !important;
            border: 1px solid var(--tugon-border) !important;
            color: var(--tugon-text) !important;
            box-shadow: var(--tugon-shadow) !important;
        }

        body.user-area .dashboard-hero,
        body.user-area .request-hero-main,
        body.user-area .certificate-hero-main,
        body.user-area .announcement-hero-main,
        body.user-area .notification-hero,
        body.user-area .calendar-user-hero,
        body.user-area .tugon-ai-header {
            background: #FFFFFF !important;
            border: 1px solid var(--tugon-border) !important;
            border-top: 3px solid var(--tugon-ocean) !important;
            color: var(--tugon-text) !important;
            box-shadow: var(--tugon-shadow) !important;
        }

        body.user-area .topbar-search,
        body.user-area .premium-search,
        body.user-area .form-control,
        body.user-area .form-select,
        body.user-area input,
        body.user-area select,
        body.user-area textarea {
            background: #FFFFFF !important;
            border-color: var(--tugon-border) !important;
            color: var(--tugon-text) !important;
            font-size: 16px !important;
            line-height: 1.5 !important;
            min-height: 44px !important;
        }

        body.user-area .form-control:focus,
        body.user-area .form-select:focus,
        body.user-area input:focus,
        body.user-area select:focus,
        body.user-area textarea:focus {
            border-color: var(--tugon-teal) !important;
            box-shadow: 0 0 0 4px rgba(20, 155, 181, 0.18) !important;
        }

        body.user-area label,
        body.user-area .form-label,
        body.user-area .pds-form-label {
            color: var(--tugon-text) !important;
            font-size: 15px !important;
            line-height: 1.45 !important;
            font-weight: 600 !important;
        }

        body.user-area .btn,
        body.user-area .premium-btn,
        body.user-area .pds-btn,
        body.user-area .submit-btn,
        body.user-area .toolbar-control,
        body.user-area .auth-submit,
        body.user-area .ai-send-btn {
            min-height: 44px !important;
            font-size: 15px !important;
            line-height: 1.35 !important;
            font-weight: 600 !important;
            border-radius: 999px !important;
        }

        body.user-area .btn-primary,
        body.user-area .premium-btn.primary,
        body.user-area .pds-btn-primary,
        body.user-area .submit-btn,
        body.user-area .ai-send-btn,
        body.user-area .tugon-ai-form button,
        body.user-area .ai-assistant-live-form button {
            background: var(--tugon-link) !important;
            border-color: var(--tugon-link) !important;
            color: #FFFFFF !important;
        }

        body.user-area .btn-primary:hover,
        body.user-area .premium-btn.primary:hover,
        body.user-area .tugon-ai-form button:hover,
        body.user-area .ai-assistant-live-form button:hover {
            background: var(--tugon-ocean) !important;
            border-color: var(--tugon-ocean) !important;
            color: #FFFFFF !important;
        }

        body.user-area .btn-outline-primary,
        body.user-area .btn-outline-secondary,
        body.user-area .premium-btn.ghost,
        body.user-area .premium-btn.secondary {
            background: #FFFFFF !important;
            border-color: var(--tugon-border) !important;
            color: var(--tugon-ocean) !important;
        }

        body.user-area a:not(.btn):not(.nav-link):not(.user-quick-tile):not(.premium-kpi-card) {
            color: var(--tugon-link) !important;
            text-decoration-thickness: 0.08em;
            text-underline-offset: 0.16em;
        }

        body.user-area .premium-kpi-card,
        body.user-area .stat-card,
        body.user-area .notification-stat,
        body.user-area .request-summary-card {
            background: var(--tugon-surface) !important;
            border: 1px solid var(--tugon-border) !important;
            color: var(--tugon-text) !important;
            box-shadow: var(--tugon-shadow) !important;
            min-height: 142px !important;
            padding: 22px !important;
        }

        body.user-area .premium-kpi-icon,
        body.user-area .stat-icon,
        body.user-area .card-icon,
        body.user-area .calendar-user-icon,
        body.user-area .notification-icon,
        body.user-area .tugon-ai-avatar,
        body.user-area .ai-assistant-panel-mark {
            background: rgba(200, 155, 60, 0.12) !important;
            color: var(--tugon-text) !important;
            border-color: var(--tugon-border) !important;
        }

        body.user-area .premium-kpi-label,
        body.user-area .stat-label,
        body.user-area .notification-stat span {
            color: var(--tugon-muted) !important;
            font-size: 15px !important;
            line-height: 1.4 !important;
            font-weight: 600 !important;
            letter-spacing: 0 !important;
            text-transform: none !important;
        }

        body.user-area .premium-kpi-value,
        body.user-area .stat-value,
        body.user-area .notification-stat strong {
            color: var(--tugon-text) !important;
            font-size: clamp(30px, 3vw, 36px) !important;
            line-height: 1.1 !important;
            font-weight: 700 !important;
        }

        body.user-area .premium-kpi-note {
            color: var(--tugon-link) !important;
            font-size: 14px !important;
            line-height: 1.5 !important;
            font-weight: 500 !important;
        }

        body.user-area .badge,
        body.user-area .status-badge,
        body.user-area .premium-status,
        body.user-area .notification-type,
        body.user-area .read-label,
        body.user-area .pill-badge {
            font-size: 13px !important;
            line-height: 1.35 !important;
            font-weight: 600 !important;
            padding: 0.32rem 0.62rem !important;
            border-radius: 999px !important;
        }

        body.user-area .badge.bg-primary,
        body.user-area .premium-status.approved,
        body.user-area .notification-type.primary {
            background: rgba(200, 155, 60, 0.12) !important;
            color: var(--tugon-ocean) !important;
        }

        body.user-area .table,
        body.user-area .premium-admin-table,
        body.user-area table {
            background: #FFFFFF !important;
            color: var(--tugon-text) !important;
            font-size: 15px !important;
        }

        body.user-area .table th,
        body.user-area .premium-admin-table th,
        body.user-area table th {
            color: var(--tugon-muted) !important;
            font-size: 14px !important;
            line-height: 1.4 !important;
            font-weight: 600 !important;
            letter-spacing: 0.04em !important;
            background: rgba(200, 155, 60, 0.12) !important;
            border-color: var(--tugon-border) !important;
        }

        body.user-area .table td,
        body.user-area .premium-admin-table td,
        body.user-area table td {
            color: var(--tugon-text) !important;
            font-size: 15px !important;
            line-height: 1.5 !important;
            border-color: var(--tugon-border) !important;
        }

        body.user-area .alert,
        body.user-area .request-secure-note,
        body.user-area .records-note,
        body.user-area .ai-command-card {
            background: var(--tugon-surface) !important;
            border: 1px solid var(--tugon-border) !important;
            color: var(--tugon-text) !important;
            font-size: 16px !important;
            line-height: 1.6 !important;
        }

        body.user-area .alert-info,
        body.user-area .request-secure-note {
            background: rgba(200, 155, 60, 0.12) !important;
        }

        body.user-area .floating-language,
        body.user-area .language-switcher a.active,
        body.user-area .icon-btn,
        body.user-area .profile-avatar {
            background: rgba(200, 155, 60, 0.12) !important;
            border-color: var(--tugon-border) !important;
            color: var(--tugon-text) !important;
        }

        body.user-area .ai-assistant-trigger,
        body.user-area .ai-assistant-panel {
            box-shadow: var(--tugon-shadow) !important;
        }

        body.user-area .ai-assistant-panel,
        body.user-area .ai-assistant-live-answer,
        body.user-area .ai-assistant-search,
        body.user-area .tugon-ai-form,
        body.user-area .tugon-ai-side-card {
            background: #FFFFFF !important;
            border-color: var(--tugon-border) !important;
            color: var(--tugon-text) !important;
        }

        body.user-area .ai-assistant-panel-header {
            background: linear-gradient(135deg, #2E3A2D, #384637) !important;
            color: #FFFFFF !important;
        }

        body.user-area .ai-assistant-quick button,
        body.user-area .tugon-ai-prompt,
        body.user-area .quick-prompt {
            background: rgba(200, 155, 60, 0.12) !important;
            border-color: var(--tugon-border) !important;
            color: var(--tugon-text) !important;
            font-size: 15px !important;
            min-height: 44px !important;
        }

        body.user-area .user-footer {
            display: none !important;
        }

        body.user-area :focus-visible {
            outline: none !important;
            box-shadow: 0 0 0 4px rgba(20, 155, 181, 0.22) !important;
        }

        @media (max-width: 768px) {
            body.user-area .user-content {
                padding-inline: 14px !important;
            }

            body.user-area h1,
            body.user-area .page-title,
            body.user-area .dashboard-hero h1,
            body.user-area .request-hero-main h1,
            body.user-area .calendar-user-title h1 {
                font-size: clamp(28px, 8vw, 34px) !important;
            }

            body.user-area .btn,
            body.user-area .premium-btn,
            body.user-area .pds-btn {
                white-space: normal !important;
            }
        }

        /* Final cream/gold user-side override. This loads after older user styles. */
        :root {
            --tugon-bg: #F8F6F1;
            --tugon-bg-deep: #FCFBF8;
            --tugon-surface: #FFFFFF;
            --tugon-ocean: #2E3A2D;
            --tugon-link: #C89B3C;
            --tugon-teal: #C89B3C;
            --tugon-aqua: rgba(200, 155, 60, 0.12);
            --tugon-stone: #E8E1D5;
            --tugon-border: #E8E1D5;
            --tugon-text: #222222;
            --tugon-muted: #6B7280;
            --tugon-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
        }

        body.user-area,
        body.user-area .user-shell,
        body.user-area .user-main,
        body.user-area .user-content,
        body.user-area .page-content,
        body.user-area .tugon-ai-page {
            background:
                linear-gradient(180deg, var(--tugon-bg) 0%, var(--tugon-bg-deep) 100%) !important;
            color: var(--tugon-text) !important;
        }

        body.user-area .user-sidebar {
            background: linear-gradient(180deg, #2E3A2D, #384637) !important;
            color: #FFFFFF !important;
            border-right-color: rgba(200, 155, 60, 0.22) !important;
            box-shadow: 12px 0 28px rgba(46, 58, 45, 0.14) !important;
        }

        body.user-area .user-sidebar .sidebar-brand {
            background: rgba(255, 255, 255, 0.04) !important;
            border-bottom-color: rgba(200, 155, 60, 0.34) !important;
            color: #FFFFFF !important;
        }

        body.user-area .user-sidebar .brand-logo,
        body.user-area .user-sidebar .pill-badge,
        body.user-area .user-sidebar .profile-dot {
            background: #C89B3C !important;
            color: #FFFFFF !important;
            border-color: rgba(184, 138, 34, 0.28) !important;
        }

        body.user-area .user-sidebar .brand-title,
        body.user-area .user-sidebar .brand-subtitle,
        body.user-area .user-sidebar .sidebar-toggle {
            color: #FFFFFF !important;
        }

        body.user-area .user-sidebar .nav-link,
        body.user-area .user-sidebar .nav-toggle,
        body.user-area .user-sidebar .nav-submenu .sublink {
            color: rgba(255, 248, 235, 0.9) !important;
        }

        body.user-area .user-sidebar .nav-section-label {
            color: rgba(255, 248, 235, 0.68) !important;
        }

        body.user-area .user-sidebar .nav-link:hover,
        body.user-area .user-sidebar .nav-link.active,
        body.user-area .user-sidebar .nav-collapsible.open .nav-toggle {
            background: #C89B3C !important;
            color: #FFFFFF !important;
            box-shadow: inset 4px 0 0 rgba(255, 255, 255, 0.78) !important;
        }

        body.user-area .user-topbar,
        body.user-area .premium-glass,
        body.user-area .topbar,
        body.user-area .card,
        body.user-area .premium-panel,
        body.user-area .dashboard-panel,
        body.user-area .dashboard-card,
        body.user-area .request-card,
        body.user-area .request-form-card,
        body.user-area .announcement-card,
        body.user-area .notification-card,
        body.user-area .calendar-panel,
        body.user-area .calendar-side-panel,
        body.user-area .tugon-ai-card,
        body.user-area .tugon-ai-welcome,
        body.user-area .tugon-ai-side,
        body.user-area .payment-card,
        body.user-area .reservation-card,
        body.user-area .premium-kpi-card {
            background: var(--tugon-surface) !important;
            border-color: var(--tugon-border) !important;
            color: var(--tugon-text) !important;
            box-shadow: var(--tugon-shadow) !important;
        }

        body.user-area .dashboard-hero,
        body.user-area .request-hero-main,
        body.user-area .certificate-hero-main,
        body.user-area .announcement-hero-main,
        body.user-area .notification-hero,
        body.user-area .calendar-user-hero,
        body.user-area .tugon-ai-header {
            background: var(--tugon-surface) !important;
            border-color: var(--tugon-border) !important;
            border-top: 3px solid var(--tugon-teal) !important;
            color: var(--tugon-text) !important;
            box-shadow: var(--tugon-shadow) !important;
        }

        body.user-area .user-welcome-hero {
            background: var(--tugon-surface) !important;
            border-color: var(--tugon-border) !important;
            box-shadow: var(--tugon-shadow) !important;
        }

        body.user-area .user-welcome-hero::before {
            content: "" !important;
            position: absolute !important;
            inset: 0 !important;
            pointer-events: none !important;
            opacity: 0.055 !important;
            background-image:
                linear-gradient(#1C1B18 0 0),
                linear-gradient(#1C1B18 0 0) !important;
            background-size: 2px 18px, 18px 2px !important;
            background-position: 50% 50%, 50% 50% !important;
            mask-image: radial-gradient(circle at 72% 48%, #000 0 40%, transparent 70%) !important;
        }

        body.user-area .user-welcome-hero::after {
            content: "\f654" !important;
            position: absolute !important;
            right: clamp(18px, 4vw, 54px) !important;
            bottom: clamp(12px, 3vw, 32px) !important;
            font-family: "Font Awesome 6 Free" !important;
            font-weight: 900 !important;
            font-size: clamp(5rem, 12vw, 9rem) !important;
            line-height: 1 !important;
            color: rgba(184, 138, 34, 0.08) !important;
            pointer-events: none !important;
        }

        body.user-area .premium-kpi-icon,
        body.user-area .stat-icon,
        body.user-area .card-icon,
        body.user-area .calendar-user-icon,
        body.user-area .notification-icon,
        body.user-area .tugon-ai-avatar,
        body.user-area .ai-assistant-panel-mark,
        body.user-area .user-quick-tile i {
            background: rgba(200, 155, 60, 0.12) !important;
            color: var(--tugon-text) !important;
            border-color: var(--tugon-border) !important;
        }

        body.user-area .user-quick-tile {
            background: var(--tugon-surface) !important;
            border-color: var(--tugon-border) !important;
            color: var(--tugon-text) !important;
            box-shadow: 0 10px 24px rgba(28, 27, 24, 0.06) !important;
        }

        body.user-area .premium-kpi-note,
        body.user-area .user-welcome-name,
        body.user-area a:not(.btn):not(.nav-link):not(.user-quick-tile):not(.premium-kpi-card) {
            color: var(--tugon-link) !important;
        }

        body.user-area .btn-primary,
        body.user-area .premium-btn.primary,
        body.user-area .pds-btn-primary,
        body.user-area .submit-btn,
        body.user-area .ai-send-btn,
        body.user-area .tugon-ai-form button,
        body.user-area .ai-assistant-live-form button {
            background: #C89B3C !important;
            border-color: #C89B3C !important;
            color: #FFFFFF !important;
        }

        body.user-area .form-control:focus,
        body.user-area .form-select:focus,
        body.user-area input:focus,
        body.user-area select:focus,
        body.user-area textarea:focus,
        body.user-area :focus-visible {
            box-shadow: 0 0 0 4px rgba(200, 155, 60, 0.22) !important;
        }
    </style>
    <?php endif; ?>
    <?php if (isset($is_admin_area) && $is_admin_area): ?>
    <style>
        :root {
            --tugon-admin-bg: #F8F6F1;
            --tugon-admin-bg-deep: #FCFBF8;
            --tugon-admin-surface: #FFFFFF;
            --tugon-admin-ocean: #2E3A2D;
            --tugon-admin-link: #C89B3C;
            --tugon-admin-teal: #C89B3C;
            --tugon-admin-aqua: rgba(200, 155, 60, 0.12);
            --tugon-admin-stone: #E8E1D5;
            --tugon-admin-border: #E8E1D5;
            --tugon-admin-text: #222222;
            --tugon-admin-muted: #6B7280;
            --tugon-admin-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
        }

        body.premium-admin,
        body.premium-admin .premium-admin-shell,
        body.premium-admin .premium-admin-content,
        body.premium-admin .admin-content {
            background:
                linear-gradient(180deg, #F8F6F1 0%, var(--tugon-admin-bg) 46%, var(--tugon-admin-bg-deep) 100%) !important;
            color: var(--tugon-admin-text) !important;
            font-family: "Inter", "Segoe UI", Arial, sans-serif !important;
            font-size: 16px !important;
            line-height: 1.6 !important;
        }

        body.premium-admin .admin-sidebar {
            background: linear-gradient(180deg, #2E3A2D, #384637) !important;
            border-right: 1px solid rgba(200, 155, 60, 0.22) !important;
            box-shadow: 12px 0 28px rgba(46, 58, 45, 0.14) !important;
        }

        body.premium-admin .admin-sidebar .sidebar-brand {
            background: rgba(255, 255, 255, 0.04) !important;
            color: #FFFFFF !important;
        }

        body.premium-admin .admin-sidebar .nav-link {
            color: rgba(255, 255, 255, 0.9) !important;
            font-size: 15px !important;
            min-height: 44px !important;
        }

        body.premium-admin .admin-sidebar .nav-link:hover,
        body.premium-admin .admin-sidebar .nav-link.active {
            background: #C89B3C !important;
            color: #FFFFFF !important;
            box-shadow: inset 4px 0 0 var(--tugon-admin-teal) !important;
        }

        body.premium-admin .admin-sidebar .nav-section-label {
            color: rgba(255, 255, 255, 0.7) !important;
            font-size: 13px !important;
            letter-spacing: 0.04em !important;
        }

        body.premium-admin h1,
        body.premium-admin .page-title,
        body.premium-admin .premium-admin-hero h1 {
            color: var(--tugon-admin-text) !important;
            font-size: clamp(28px, 3vw, 36px) !important;
            line-height: 1.25 !important;
            font-weight: 700 !important;
        }

        body.premium-admin h2,
        body.premium-admin .section-title,
        body.premium-admin .premium-panel-title {
            color: var(--tugon-admin-text) !important;
            font-size: clamp(20px, 2vw, 24px) !important;
            line-height: 1.3 !important;
            font-weight: 700 !important;
        }

        body.premium-admin p,
        body.premium-admin li,
        body.premium-admin .text-muted {
            color: var(--tugon-admin-text) !important;
            font-size: 16px !important;
            line-height: 1.6 !important;
        }

        body.premium-admin .premium-glass,
        body.premium-admin .premium-panel,
        body.premium-admin .card,
        body.premium-admin .dashboard-card,
        body.premium-admin .request-card,
        body.premium-admin .registry-card,
        body.premium-admin .report-card,
        body.premium-admin .analytics-card {
            background: var(--tugon-admin-surface) !important;
            border: 1px solid var(--tugon-admin-border) !important;
            color: var(--tugon-admin-text) !important;
            box-shadow: var(--tugon-admin-shadow) !important;
        }

        body.premium-admin .premium-admin-hero,
        body.premium-admin .records-hero,
        body.premium-admin .audit-hero,
        body.premium-admin .integration-hero,
        body.premium-admin .ai-page-hero {
            background: #FFFFFF !important;
            border: 1px solid var(--tugon-admin-border) !important;
            border-top: 3px solid var(--tugon-admin-teal) !important;
            color: var(--tugon-admin-text) !important;
        }

        body.premium-admin .btn-primary,
        body.premium-admin .btn-primary-gold,
        body.premium-admin .premium-btn.primary,
        body.premium-admin .pds-btn-primary,
        body.premium-admin .pds-btn-primary-gold,
        body.premium-admin .submit-btn {
            background: var(--tugon-admin-link) !important;
            border-color: var(--tugon-admin-link) !important;
            color: #FFFFFF !important;
        }

        body.premium-admin .form-control,
        body.premium-admin .form-select,
        body.premium-admin input,
        body.premium-admin select,
        body.premium-admin textarea {
            background: #FFFFFF !important;
            border-color: var(--tugon-admin-border) !important;
            color: var(--tugon-admin-text) !important;
            font-size: 16px !important;
            min-height: 44px !important;
        }

        /* Final warm cream/gold admin override. */
        :root {
            --tugon-admin-bg: #F8F6F1;
            --tugon-admin-bg-deep: #FCFBF8;
            --tugon-admin-surface: #FFFFFF;
            --tugon-admin-ocean: #2E3A2D;
            --tugon-admin-link: #C89B3C;
            --tugon-admin-teal: #C89B3C;
            --tugon-admin-aqua: rgba(200, 155, 60, 0.12);
            --tugon-admin-stone: #E8E1D5;
            --tugon-admin-border: #E8E1D5;
            --tugon-admin-text: #222222;
            --tugon-admin-muted: #6B7280;
            --tugon-admin-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
        }

        body.premium-admin,
        body.premium-admin .premium-admin-shell,
        body.premium-admin .premium-admin-content,
        body.premium-admin .admin-content {
            background:
                linear-gradient(180deg, var(--tugon-admin-bg) 0%, var(--tugon-admin-bg-deep) 100%) !important;
            color: var(--tugon-admin-text) !important;
        }

        body.premium-admin .admin-sidebar {
            background: linear-gradient(180deg, #2E3A2D, #384637) !important;
            border-right-color: rgba(200, 155, 60, 0.22) !important;
            box-shadow: 12px 0 28px rgba(46, 58, 45, 0.14) !important;
        }

        body.premium-admin .admin-sidebar .sidebar-brand {
            background: rgba(255, 255, 255, 0.04) !important;
            color: #FFFFFF !important;
        }

        body.premium-admin .admin-sidebar .nav-link:hover,
        body.premium-admin .admin-sidebar .nav-link.active {
            background: #C89B3C !important;
            color: #FFFFFF !important;
            box-shadow: inset 4px 0 0 rgba(255, 255, 255, 0.78) !important;
        }

        body.premium-admin .premium-admin-hero,
        body.premium-admin .records-hero,
        body.premium-admin .audit-hero,
        body.premium-admin .integration-hero,
        body.premium-admin .ai-page-hero,
        body.premium-admin .premium-glass,
        body.premium-admin .premium-panel,
        body.premium-admin .card,
        body.premium-admin .dashboard-card,
        body.premium-admin .request-card,
        body.premium-admin .registry-card,
        body.premium-admin .report-card,
        body.premium-admin .analytics-card {
            background: #FFFFFF !important;
            border-color: var(--tugon-admin-border) !important;
            color: var(--tugon-admin-text) !important;
            box-shadow: var(--tugon-admin-shadow) !important;
        }

        body.premium-admin .premium-admin-hero,
        body.premium-admin .records-hero,
        body.premium-admin .audit-hero,
        body.premium-admin .integration-hero,
        body.premium-admin .ai-page-hero {
            border-top: 3px solid var(--tugon-admin-teal) !important;
        }

        body.premium-admin .btn-primary,
        body.premium-admin .btn-primary-gold,
        body.premium-admin .premium-btn.primary,
        body.premium-admin .pds-btn-primary,
        body.premium-admin .pds-btn-primary-gold,
        body.premium-admin .submit-btn {
            background: #C89B3C !important;
            border-color: #C89B3C !important;
            color: #FFFFFF !important;
        }

        body.premium-admin .form-control,
        body.premium-admin .form-select,
        body.premium-admin input,
        body.premium-admin select,
        body.premium-admin textarea {
            border-color: var(--tugon-admin-border) !important;
            color: var(--tugon-admin-text) !important;
        }
    </style>
    <?php endif; ?>
    <style>
        /* Final cathedral enterprise UI lock. Visual-only, scoped to system shells. */
        body.user-area,
        body.premium-admin,
        body.user-area .user-shell,
        body.premium-admin .premium-admin-shell,
        body.user-area .user-content,
        body.premium-admin .premium-admin-content,
        body.premium-admin .admin-content {
            background: #F8F6F1 !important;
            color: #222222 !important;
            font-family: "Inter", "Segoe UI", Arial, sans-serif !important;
        }

        body.user-area .user-sidebar,
        body.premium-admin .admin-sidebar {
            background: linear-gradient(180deg, #2E3A2D, #384637) !important;
            color: #F5F5F5 !important;
            border-right: 1px solid rgba(200, 155, 60, 0.24) !important;
            box-shadow: 12px 0 28px rgba(46, 58, 45, 0.14) !important;
        }

        body.user-area .user-sidebar .nav-link,
        body.user-area .user-sidebar .nav-toggle,
        body.premium-admin .admin-sidebar .nav-link,
        body.premium-admin .admin-sidebar .nav-toggle {
            color: #F5F5F5 !important;
            border-radius: 12px !important;
        }

        body.user-area .user-sidebar .nav-link:hover,
        body.user-area .user-sidebar .nav-toggle:hover,
        body.premium-admin .admin-sidebar .nav-link:hover,
        body.premium-admin .admin-sidebar .nav-toggle:hover {
            background: #384637 !important;
            color: #FFFFFF !important;
        }

        body.user-area .user-sidebar .nav-link.active,
        body.premium-admin .admin-sidebar .nav-link.active,
        body.premium-admin .admin-sidebar .nav-submenu .sublink.active {
            background: #C89B3C !important;
            color: #FFFFFF !important;
            border-color: #C89B3C !important;
        }

        body.user-area .card,
        body.user-area .premium-panel,
        body.user-area .dashboard-card,
        body.user-area .request-card,
        body.user-area .announcement-card,
        body.user-area .notification-card,
        body.user-area .calendar-panel,
        body.user-area .reservation-card,
        body.premium-admin .card,
        body.premium-admin .premium-panel,
        body.premium-admin .premium-glass,
        body.premium-admin .dashboard-card,
        body.premium-admin .premium-kpi-card,
        body.premium-admin .request-card,
        body.premium-admin .registry-card,
        body.premium-admin .analytics-card,
        body.premium-admin .report-card,
        body.premium-admin .table-responsive,
        body.user-area .modal-content,
        body.premium-admin .modal-content {
            background: #FFFFFF !important;
            border: 1px solid #E8E1D5 !important;
            border-radius: 16px !important;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05) !important;
            color: #222222 !important;
        }

        body.user-area .btn-primary,
        body.user-area .premium-btn.primary,
        body.user-area .submit-btn,
        body.premium-admin .btn-primary,
        body.premium-admin .premium-btn.primary,
        body.premium-admin .submit-btn,
        body.premium-admin .dashboard-action-btn.primary,
        body.premium-admin .dashboard-action-btn.gold {
            background: #C89B3C !important;
            border-color: #C89B3C !important;
            color: #FFFFFF !important;
        }

        body.user-area input,
        body.user-area select,
        body.user-area textarea,
        body.user-area .form-control,
        body.user-area .form-select,
        body.premium-admin input,
        body.premium-admin select,
        body.premium-admin textarea,
        body.premium-admin .form-control,
        body.premium-admin .form-select {
            background: #FFFFFF !important;
            border-color: #E8E1D5 !important;
            border-radius: 12px !important;
            color: #222222 !important;
        }

        body.user-area input:focus,
        body.user-area select:focus,
        body.user-area textarea:focus,
        body.premium-admin input:focus,
        body.premium-admin select:focus,
        body.premium-admin textarea:focus {
            border-color: #C89B3C !important;
            box-shadow: 0 0 0 4px rgba(200, 155, 60, 0.18) !important;
        }
    </style>
    <script>
        window.parishInitialNotifications = <?php echo json_encode($action_notifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    </script>
    <style id="tugon-ai-floating-head-styles">
        /* ══════════════════════════════════════════════════════════════════
           TUGON AI ASSISTANT — WARM INSTITUTIONAL PARISH REDESIGN
           ══════════════════════════════════════════════════════════════════ */
        .ai-assistant-widget {
            position: fixed !important;
            bottom: 24px !important;
            right: 24px !important;
            z-index: 99999 !important;
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            pointer-events: auto !important;
            width: auto !important;
            height: auto !important;
            margin: 0 !important;
            padding: 0 !important;
            inset: auto 24px 24px auto !important;
            font-family: "Plus Jakarta Sans", "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
        }
        
        /* ── Floating Launcher Trigger (Closed State) ───────────────── */
        .ai-assistant-trigger {
            position: relative !important;
            width: 60px !important;
            height: 60px !important;
            min-width: 60px !important;
            min-height: 60px !important;
            border-radius: 50% !important;
            background: linear-gradient(135deg, #1C2A22 0%, #2E3A2D 100%) !important;
            color: #FFFFFF !important;
            border: 2.5px solid #C89B3C !important;
            box-shadow: 0 10px 28px rgba(22, 33, 24, 0.45), 0 2px 8px rgba(200, 155, 60, 0.35) !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 1.45rem !important;
            cursor: pointer !important;
            visibility: visible !important;
            opacity: 1 !important;
            pointer-events: auto !important;
            transition: transform 0.24s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.24s ease !important;
            text-decoration: none !important;
        }
        .ai-assistant-trigger:hover {
            transform: scale(1.06) translateY(-2px) !important;
            box-shadow: 0 14px 34px rgba(200, 155, 60, 0.45), 0 4px 12px rgba(22, 33, 24, 0.35) !important;
            color: #FFFFFF !important;
        }
        .ai-assistant-icon {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            color: #FFFFFF !important;
            position: relative !important;
        }
        .ai-assistant-icon .fa-robot {
            font-size: 1.55rem !important;
            color: #FDFBF7 !important;
            filter: drop-shadow(0 1px 3px rgba(0, 0, 0, 0.3));
        }
        .ai-assistant-online-indicator {
            position: absolute !important;
            top: 2px !important;
            right: 2px !important;
            width: 13px !important;
            height: 13px !important;
            border-radius: 50% !important;
            background: #10B981 !important;
            border: 2px solid #1C2A22 !important;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.4) !important;
            animation: aiPulseOnline 2s infinite !important;
        }
        @keyframes aiPulseOnline {
            0%, 100% { transform: scale(1); opacity: 1; box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.4); }
            50% { transform: scale(1.18); opacity: 0.85; box-shadow: 0 0 0 5px rgba(16, 185, 129, 0.15); }
        }
        .ai-assistant-chathead-label {
            position: absolute !important;
            bottom: -8px !important;
            left: 50% !important;
            transform: translateX(-50%) !important;
            background: #1C2A22 !important;
            color: #FFFFFF !important;
            border: 1px solid #C89B3C !important;
            padding: 2px 8px !important;
            border-radius: 999px !important;
            font-size: 0.68rem !important;
            font-weight: 700 !important;
            letter-spacing: 0.4px !important;
            white-space: nowrap !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.28) !important;
            pointer-events: none !important;
        }
        .ai-assistant-widget.is-open .ai-assistant-trigger {
            display: none !important;
        }

        /* ── Main Chat Panel Window (Open State) ────────────────────── */
        .ai-assistant-panel {
            position: fixed !important;
            bottom: 24px !important;
            right: 24px !important;
            width: 410px !important;
            max-width: calc(100vw - 32px) !important;
            height: 580px !important;
            max-height: calc(100vh - 48px) !important;
            border-radius: 20px !important;
            border: 1.5px solid rgba(200, 155, 60, 0.45) !important;
            background: #FAF8F5 !important;
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.3), 0 6px 20px rgba(0, 0, 0, 0.08) !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
            z-index: 99999 !important;
            transform: translateY(16px) scale(0.95) !important;
            opacity: 0 !important;
            pointer-events: none !important;
            transition: transform 0.24s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.22s ease !important;
        }
        .ai-assistant-widget.is-open .ai-assistant-panel {
            display: flex !important;
            opacity: 1 !important;
            pointer-events: auto !important;
            transform: translateY(0) scale(1) !important;
        }

        /* ── Header ─────────────────────────────────────────────────── */
        .ai-assistant-panel-header {
            background: linear-gradient(135deg, #1C2A22 0%, #2E3A2D 100%) !important;
            color: #FFFFFF !important;
            border-bottom: 1.5px solid #C89B3C !important;
            padding: 13px 16px !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12) !important;
            user-select: none !important;
        }
        .ai-assistant-mobile-back {
            display: none !important;
            background: transparent !important;
            border: 0 !important;
            color: #FFFFFF !important;
            font-size: 1.1rem !important;
            padding: 0 4px !important;
            cursor: pointer !important;
        }
        .ai-assistant-panel-mark {
            width: 36px !important;
            height: 36px !important;
            border-radius: 11px !important;
            background: linear-gradient(135deg, #FAF3E0 0%, #F5E6BE 100%) !important;
            border: 1.5px solid #C89B3C !important;
            color: #8A6409 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 1.15rem !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15) !important;
            flex-shrink: 0 !important;
        }
        .ai-assistant-panel-identity {
            display: flex !important;
            flex-direction: column !important;
            flex: 1 1 auto !important;
            min-width: 0 !important;
        }
        .ai-assistant-panel-identity strong {
            font-size: 0.95rem !important;
            color: #FFFFFF !important;
            font-weight: 700 !important;
            line-height: 1.2 !important;
            letter-spacing: -0.2px !important;
        }
        .ai-assistant-panel-identity span {
            font-size: 0.72rem !important;
            color: #E2CE98 !important;
            font-weight: 600 !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 5px !important;
            margin-top: 2px !important;
        }
        .ai-assistant-status-dot {
            width: 7px !important;
            height: 7px !important;
            border-radius: 50% !important;
            background: #10B981 !important;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.35) !important;
            display: inline-block !important;
        }
        .ai-assistant-tool,
        .ai-assistant-close {
            background: rgba(255, 255, 255, 0.08) !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            color: rgba(255, 255, 255, 0.85) !important;
            width: 30px !important;
            height: 30px !important;
            border-radius: 8px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            font-size: 0.85rem !important;
            transition: all 0.18s ease !important;
        }
        .ai-assistant-tool:hover,
        .ai-assistant-close:hover {
            color: #FFFFFF !important;
            background: rgba(200, 155, 60, 0.25) !important;
            border-color: #C89B3C !important;
            transform: scale(1.08) !important;
        }

        /* ── Panel Body & Scrollable Area ───────────────────────────── */
        .ai-assistant-panel-body {
            display: flex !important;
            flex-direction: column !important;
            flex: 1 1 auto !important;
            height: calc(100% - 62px) !important;
            overflow: hidden !important;
            background: #FAF8F5 !important;
        }
        .ai-assistant-live-answer {
            flex: 1 1 auto !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding: 16px !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 14px !important;
            scrollbar-width: thin;
            scrollbar-color: rgba(200, 155, 60, 0.4) transparent;
        }
        .ai-assistant-live-answer::-webkit-scrollbar {
            width: 5px !important;
        }
        .ai-assistant-live-answer::-webkit-scrollbar-track {
            background: transparent !important;
        }
        .ai-assistant-live-answer::-webkit-scrollbar-thumb {
            background: rgba(200, 155, 60, 0.35) !important;
            border-radius: 10px !important;
        }
        .ai-assistant-live-answer::-webkit-scrollbar-thumb:hover {
            background: rgba(200, 155, 60, 0.75) !important;
        }

        /* ── Empty State / Welcome Area ─────────────────────────────── */
        .ai-assistant-empty-state {
            display: flex !important;
            flex-direction: column !important;
            gap: 12px !important;
            animation: aiFadeInUp 0.25s ease both !important;
        }
        .ai-assistant-welcome-icon {
            width: 48px !important;
            height: 48px !important;
            margin: 4px auto 8px !important;
            border-radius: 14px !important;
            background: linear-gradient(135deg, #FDE68A 0%, #D97706 100%) !important;
            color: #78350F !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 1.45rem !important;
            box-shadow: 0 4px 18px rgba(217, 119, 6, 0.3) !important;
        }
        .ai-assistant-greeting-bubble {
            background: #FFFFFF !important;
            border: 1px solid #EAE5DB !important;
            border-radius: 16px !important;
            padding: 16px 18px !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04) !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 6px !important;
        }
        .ai-assistant-greeting-bubble strong {
            font-size: 0.95rem !important;
            font-weight: 700 !important;
            color: #1E293B !important;
        }
        .ai-assistant-greeting-bubble span {
            font-size: 0.83rem !important;
            color: #475569 !important;
            line-height: 1.5 !important;
        }

        /* ── Quick-Reply 2-Column Grid ──────────────────────────────── */
        .ai-assistant-quick-heading {
            font-size: 0.7rem !important;
            font-weight: 700 !important;
            letter-spacing: 0.8px !important;
            color: #8A7240 !important;
            text-transform: uppercase !important;
            margin: 6px 0 2px 2px !important;
        }
        .ai-assistant-quick {
            display: grid !important;
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 8px !important;
        }
        .ai-assistant-quick button {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            padding: 9px 10px !important;
            background: #FFFFFF !important;
            border: 1px solid #E8E2D5 !important;
            border-radius: 12px !important;
            color: #1E293B !important;
            font-size: 0.76rem !important;
            font-weight: 600 !important;
            line-height: 1.25 !important;
            text-align: left !important;
            cursor: pointer !important;
            transition: all 0.18s ease !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03) !important;
        }
        .ai-assistant-quick button:hover {
            background: #FAF6ED !important;
            border-color: #C89B3C !important;
            color: #8A6409 !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(200, 155, 60, 0.16) !important;
        }
        .ai-assistant-quick button:active {
            transform: scale(0.98) !important;
        }
        .ai-chip-icon {
            width: 26px !important;
            height: 26px !important;
            border-radius: 7px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 11px !important;
            flex-shrink: 0 !important;
        }
        .chip-cert { background: #EFF6FF !important; color: #2563EB !important; border: 1px solid #BFDBFE !important; }
        .chip-track { background: #FEF3C7 !important; color: #D97706 !important; border: 1px solid #FDE68A !important; }
        .chip-sched { background: #ECFDF5 !important; color: #059669 !important; border: 1px solid #A7F3D0 !important; }
        .chip-bless { background: #FAF5FF !important; color: #7C3AED !important; border: 1px solid #E9D5FF !important; }
        .chip-news { background: #FFF1F2 !important; color: #E11D48 !important; border: 1px solid #FECDD3 !important; }
        .chip-pay { background: #F0FDF4 !important; color: #16A34A !important; border: 1px solid #BBF7D0 !important; }
        .ai-chip-text {
            flex: 1 1 auto !important;
            white-space: normal !important;
        }

        /* ── Chat Messages (AI vs User) ─────────────────────────────── */
        .ai-assistant-chat-message {
            display: flex !important;
            flex-direction: column !important;
            gap: 4px !important;
            animation: aiFadeInUp 0.2s ease both !important;
        }
        .ai-assistant-chat-message.user {
            align-self: flex-end !important;
            max-width: 84% !important;
        }
        .ai-assistant-chat-message.assistant {
            align-self: flex-start !important;
            max-width: 90% !important;
        }
        .ai-msg-header {
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
            margin-bottom: 2px !important;
        }
        .ai-avatar-badge {
            width: 20px !important;
            height: 20px !important;
            border-radius: 6px !important;
            background: rgba(200, 155, 60, 0.2) !important;
            color: #C89B3C !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 10px !important;
        }
        .ai-msg-header strong {
            font-size: 0.76rem !important;
            font-weight: 700 !important;
            color: #2E3A2D !important;
        }
        .ai-msg-bubble {
            padding: 11px 14px !important;
            font-size: 0.85rem !important;
            line-height: 1.5 !important;
            word-break: break-word !important;
        }
        .ai-msg-bubble.user-bubble {
            background: linear-gradient(135deg, #2E3A2D 0%, #1E271D 100%) !important;
            color: #FFFFFF !important;
            border: 1px solid rgba(200, 155, 60, 0.35) !important;
            border-radius: 16px 16px 4px 16px !important;
            box-shadow: 0 2px 8px rgba(22, 33, 24, 0.15) !important;
        }
        .ai-msg-bubble.user-bubble p {
            margin: 0 !important;
            color: #FFFFFF !important;
        }
        .ai-msg-bubble.assistant-bubble {
            background: #FFFFFF !important;
            color: #1E293B !important;
            border: 1px solid #E5E0D6 !important;
            border-radius: 4px 16px 16px 16px !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04) !important;
        }
        .ai-msg-bubble.assistant-bubble p {
            margin: 0 0 6px 0 !important;
        }
        .ai-msg-bubble.assistant-bubble p:last-child {
            margin-bottom: 0 !important;
        }
        .ai-msg-bubble.assistant-bubble strong {
            color: #1E293B !important;
            font-weight: 700 !important;
        }
        .ai-assistant-bullet-list,
        .ai-assistant-ordered-list,
        .ai-assistant-numbered-list {
            margin: 6px 0 !important;
            padding-left: 18px !important;
        }
        .ai-assistant-bullet-list li,
        .ai-assistant-ordered-list li,
        .ai-assistant-numbered-list li {
            margin-bottom: 4px !important;
            font-size: 0.83rem !important;
            color: #334155 !important;
        }
        .ai-action-btn {
            display: inline-flex !important;
            align-items: center !important;
            gap: 5px !important;
            padding: 4px 10px !important;
            background: #FAF5E8 !important;
            border: 1px solid #C89B3C !important;
            border-radius: 6px !important;
            color: #8A6409 !important;
            font-weight: 600 !important;
            font-size: 11.5px !important;
            text-decoration: none !important;
            margin-top: 6px !important;
            transition: all 0.15s ease !important;
        }
        .ai-action-btn:hover {
            background: #F3E7C9 !important;
            color: #694b05 !important;
            transform: translateY(-1px) !important;
        }
        .ai-assistant-message-meta {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            font-size: 10.5px !important;
            color: #94A3B8 !important;
            padding: 0 2px !important;
        }
        .ai-assistant-copy {
            background: transparent !important;
            border: none !important;
            color: #94A3B8 !important;
            font-size: 11px !important;
            cursor: pointer !important;
            padding: 2px 4px !important;
            border-radius: 4px !important;
            transition: color 0.15s ease !important;
        }
        .ai-assistant-copy:hover {
            color: #C89B3C !important;
        }

        /* ── Typing Indicator Dots ──────────────────────────────────── */
        .ai-typing-bubble {
            padding: 12px 16px !important;
            display: inline-flex !important;
            align-items: center !important;
        }
        .ai-typing-dots {
            display: flex !important;
            align-items: center !important;
            gap: 5px !important;
        }
        .ai-typing-dots span {
            width: 7px !important;
            height: 7px !important;
            border-radius: 50% !important;
            background: #C89B3C !important;
            animation: aiTypingBounce 1.3s infinite ease-in-out both !important;
        }
        .ai-typing-dots span:nth-child(1) { animation-delay: -0.32s !important; }
        .ai-typing-dots span:nth-child(2) { animation-delay: -0.16s !important; }
        @keyframes aiTypingBounce {
            0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
            40% { transform: scale(1.1); opacity: 1; }
        }

        /* ── Input Form Footer ──────────────────────────────────────── */
        .ai-assistant-live-form {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            padding: 12px 14px !important;
            background: #FFFFFF !important;
            border-top: 1px solid #EAE5DB !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
        .ai-assistant-search {
            flex: 1 1 auto !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            background: #F8F6F0 !important;
            border: 1.5px solid #E2DCD0 !important;
            border-radius: 22px !important;
            padding: 4px 12px !important;
            margin: 0 !important;
            transition: all 0.18s ease !important;
        }
        .ai-assistant-search:focus-within {
            background: #FFFFFF !important;
            border-color: #C89B3C !important;
            box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.15) !important;
        }
        .ai-assistant-search i {
            color: #94A3B8 !important;
            font-size: 13px !important;
            flex-shrink: 0 !important;
        }
        .ai-assistant-search textarea {
            width: 100% !important;
            border: none !important;
            background: transparent !important;
            outline: none !important;
            font-size: 0.85rem !important;
            color: #1E293B !important;
            line-height: 1.4 !important;
            padding: 6px 0 !important;
            resize: none !important;
            max-height: 80px !important;
            font-family: inherit !important;
        }
        .ai-assistant-live-form button[type="submit"] {
            flex: 0 0 38px !important;
            width: 38px !important;
            height: 38px !important;
            min-width: 38px !important;
            min-height: 38px !important;
            border-radius: 50% !important;
            border: none !important;
            background: linear-gradient(135deg, #D4AF37 0%, #B8860B 100%) !important;
            color: #FFFFFF !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            box-shadow: 0 3px 10px rgba(184, 134, 11, 0.35) !important;
            transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease !important;
            padding: 0 !important;
        }
        .ai-assistant-live-form button[type="submit"]:hover:not(:disabled) {
            transform: scale(1.08) !important;
            box-shadow: 0 5px 16px rgba(184, 134, 11, 0.5) !important;
        }
        .ai-assistant-live-form button[type="submit"]:disabled {
            background: #E2E8F0 !important;
            color: #94A3B8 !important;
            box-shadow: none !important;
            cursor: not-allowed !important;
            transform: none !important;
            opacity: 0.65 !important;
        }
        .ai-assistant-send-label {
            display: none !important;
        }

        /* ── Animations ─────────────────────────────────────────────── */
        @keyframes aiFadeInUp {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Minimized & Responsive ─────────────────────────────────── */
        .ai-assistant-panel.is-minimized {
            height: 58px !important;
            max-height: 58px !important;
            overflow: hidden !important;
        }
        .ai-assistant-panel.is-minimized .ai-assistant-panel-body {
            display: none !important;
        }

        @media (max-width: 599px) {
            .ai-assistant-widget {
                bottom: calc(16px + env(safe-area-inset-bottom)) !important;
                right: 16px !important;
                inset: auto 16px calc(16px + env(safe-area-inset-bottom)) auto !important;
            }
            .ai-assistant-widget.is-open .ai-assistant-panel {
                inset: 0 !important;
                width: 100vw !important;
                height: 100dvh !important;
                max-width: none !important;
                max-height: none !important;
                border-radius: 0 !important;
            }
            .ai-assistant-mobile-back {
                display: inline-flex !important;
            }
            .ai-assistant-quick {
                grid-template-columns: 1fr !important;
            }
        }
    </style>

    <?php if (isLoggedIn() && empty($is_admin_area)): ?>
    <div class="ai-assistant-widget" id="aiAssistantWidget">
        <button class="ai-assistant-trigger" type="button" id="aiAssistantTrigger" aria-label="Open TUGON AI" aria-expanded="false" title="Need help? Chat with TUGON AI">
            <span class="ai-assistant-online-indicator" aria-hidden="true"></span>
            <span class="ai-assistant-icon" aria-hidden="true">
                <i class="fas fa-robot"></i>
            </span>
            <span class="ai-assistant-chathead-label">TUGON AI</span>
        </button>
        <section class="ai-assistant-panel" id="aiAssistantPanel" aria-hidden="true" role="dialog" aria-label="TUGON AI Parish Assistant">
            <div class="ai-assistant-panel-header">
                <button class="ai-assistant-mobile-back" type="button" id="aiAssistantMobileBack" aria-label="Back to previous screen">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </button>
                <div class="ai-assistant-panel-mark" aria-hidden="true">
                    <i class="fas fa-robot"></i>
                </div>
                <div class="ai-assistant-panel-identity">
                    <strong>TUGON AI Assistant</strong>
                    <span><span class="ai-assistant-status-dot" aria-hidden="true"></span> Online &amp; Ready</span>
                </div>
                <button class="ai-assistant-tool" type="button" id="aiAssistantClear" aria-label="Clear conversation" title="Clear conversation">
                    <i class="fas fa-trash-can"></i>
                </button>
                <button class="ai-assistant-tool" type="button" id="aiAssistantMinimize" aria-label="Minimize chat" title="Minimize chat">
                    <i class="fas fa-minus"></i>
                </button>
                <button class="ai-assistant-close" type="button" id="aiAssistantClose" aria-label="<?php echo e(t('chatbot.close_label', 'Close AI assistant')); ?>" title="Close chat">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ai-assistant-panel-body">
                <div class="ai-assistant-live-answer" id="aiAssistantLiveAnswer">
                    <div class="ai-assistant-empty-state" id="aiAssistantEmptyState">
                        <div class="ai-assistant-welcome-icon" aria-hidden="true">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="ai-assistant-greeting-bubble">
                            <strong>Hello! I'm TUGON AI.</strong>
                            <span>Need help? Ask me about certificate requirements, request status, Mass schedules, GCash verification, and parish services.</span>
                        </div>
                        <div class="ai-assistant-quick-heading">Quick Questions:</div>
                        <div class="ai-assistant-quick" aria-label="Suggested questions">
                            <button type="button" data-ai-prompt="How do I request a certificate?">
                                <span class="ai-chip-icon chip-cert"><i class="fas fa-file-lines"></i></span>
                                <span class="ai-chip-text">Request Certificate</span>
                            </button>
                            <button type="button" data-ai-prompt="What is the status of my request?">
                                <span class="ai-chip-icon chip-track"><i class="fas fa-list-check"></i></span>
                                <span class="ai-chip-text">Track My Request</span>
                            </button>
                            <button type="button" data-ai-prompt="Where can I see the parish schedule?">
                                <span class="ai-chip-icon chip-sched"><i class="fas fa-calendar-days"></i></span>
                                <span class="ai-chip-text">Parish Schedule</span>
                            </button>
                            <button type="button" data-ai-prompt="How do I request a blessing?">
                                <span class="ai-chip-icon chip-bless"><i class="fas fa-hands-praying"></i></span>
                                <span class="ai-chip-text">Request Blessing</span>
                            </button>
                            <button type="button" data-ai-prompt="Where can I see parish announcements?">
                                <span class="ai-chip-icon chip-news"><i class="fas fa-bullhorn"></i></span>
                                <span class="ai-chip-text">Announcements</span>
                            </button>
                            <button type="button" data-ai-prompt="How do I verify payment with GCash?">
                                <span class="ai-chip-icon chip-pay"><i class="fas fa-receipt"></i></span>
                                <span class="ai-chip-text">GCash Payment</span>
                            </button>
                        </div>
                    </div>
                </div>
                <form class="ai-assistant-live-form" id="aiAssistantLiveForm">
                    <label class="ai-assistant-search" for="aiAssistantLiveInput">
                        <i class="fas fa-message" aria-hidden="true"></i>
                        <textarea id="aiAssistantLiveInput" rows="1" maxlength="2000" data-no-autocomplete="true" placeholder="Ask about certificates, Mass, schedules..."></textarea>
                    </label>
                    <button type="submit" id="aiAssistantSendBtn" aria-label="<?php echo e(t('chatbot.send', 'Send')); ?>" title="Send message" disabled><i class="fas fa-paper-plane" aria-hidden="true"></i><span class="ai-assistant-send-label"><?php echo e(t('chatbot.send', 'Send')); ?></span></button>
                </form>
            </div>
        </section>
    </div>
    <?php endif; ?>

    <!-- Final guard matches the critical head rule, preventing a late inline style from flashing another sidebar color. -->
    <style id="final-sidebar-colors">
        .admin-sidebar,
        .premium-admin-sidebar,
        .user-sidebar {
            background: linear-gradient(180deg, #2E3A2D, #384637) !important;
            color: #FFFFFF !important;
            border-color: rgba(255, 255, 255, 0.14) !important;
        }
    </style>
    <?php if (isset($is_admin_area) && $is_admin_area): ?>
    <?php $canonical_admin_sidebar_version = filemtime(__DIR__ . '/../assets/css/admin-sidebar.css'); ?>
    <link rel="stylesheet" href="../assets/css/admin-sidebar.css?v=<?php echo $canonical_admin_sidebar_version; ?>">
    <?php endif; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JS -->
    <?php if (isLoggedIn() && empty($is_admin_area)): ?>
    <script>
        (function() {
            const widget = document.getElementById('aiAssistantWidget');
            const trigger = document.getElementById('aiAssistantTrigger');
            const panel = document.getElementById('aiAssistantPanel');
            const close = document.getElementById('aiAssistantClose');
            const mobileBack = document.getElementById('aiAssistantMobileBack');
            const clear = document.getElementById('aiAssistantClear');
            const minimize = document.getElementById('aiAssistantMinimize');
            const status = document.getElementById('aiAssistantStatus');
            const liveForm = document.getElementById('aiAssistantLiveForm');
            const liveInput = document.getElementById('aiAssistantLiveInput');
            const liveAnswer = document.getElementById('aiAssistantLiveAnswer');
            const liveSubmit = liveForm ? liveForm.querySelector('button[type="submit"]') : null;
            const conversationHistory = [];
            let assistantCsrfToken = <?php echo json_encode(generateCsrfToken()); ?>;
            let lastHealthCheckAt = 0;
            const chatLabels = {
                title: <?php echo json_encode(t('chatbot.title', 'TUGON AI Parish Assistant')); ?>,
                you: <?php echo json_encode(t('chatbot.you', 'You')); ?>,
                typing: <?php echo json_encode(t('chatbot.typing', 'TUGON AI is typing')); ?>,
                unable: <?php echo json_encode(t('chatbot.unable', 'Unable to answer right now.')); ?>,
                noAnswer: <?php echo json_encode(t('chatbot.no_answer', 'I could not find a Tugon answer for that question.')); ?>,
                endpointError: <?php echo json_encode(t('chatbot.endpoint_error', 'Unable to reach the chatbot endpoint. Please try again.')); ?>
            };

            if (!widget || !trigger || !panel || !close) {
                return;
            }

            const assistantPositionKey = 'tugonAiFabPosition:v1:<?php echo intval($_SESSION['user_id'] ?? 0); ?>';
            const desktopPanelPositionKey = 'tugonAiPanelDesktopPos:v1:<?php echo intval($_SESSION['user_id'] ?? 0); ?>';
            const assistantPhoneView = window.matchMedia('(max-width: 599px)');
            const isDesktopView = window.matchMedia('(min-width: 1024px)');
            const assistantDragThreshold = 8;
            const assistantEdgeMargin = 10;
            const panelHeader = panel.querySelector('.ai-assistant-panel-header');
            let assistantDragState = null;
            let assistantDragFrame = 0;
            let suppressNextAssistantClick = false;
            let assistantLongPressTimer = 0;
            let desktopPanelDragState = null;
            let desktopPanelDragFrame = 0;

            function assistantViewport() {
                const viewport = window.visualViewport;
                return {
                    left: viewport ? viewport.offsetLeft : 0,
                    top: viewport ? viewport.offsetTop : 0,
                    width: viewport ? viewport.width : window.innerWidth,
                    height: viewport ? viewport.height : window.innerHeight
                };
            }

            function assistantBottomLimit(viewport) {
                let limit = viewport.top + viewport.height - assistantEdgeMargin;
                const avoidSelectors = [
                    '.user-bottom-nav',
                    '.mobile-sticky-cta',
                    '.sticky-cta',
                    '.sticky-action-bar',
                    '[data-mobile-sticky-cta]'
                ];
                document.querySelectorAll(avoidSelectors.join(',')).forEach(function(element) {
                    const style = window.getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    const visible = style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
                    const isBottomControl = rect.bottom >= viewport.top + viewport.height - 4 && rect.top > viewport.top;
                    if (visible && isBottomControl) {
                        limit = Math.min(limit, rect.top - assistantEdgeMargin);
                    }
                });
                return limit;
            }

            function clampAssistantPosition(left, top) {
                const viewport = assistantViewport();
                const rect = trigger.getBoundingClientRect();
                const width = rect.width || 54;
                const height = rect.height || 54;
                const minLeft = viewport.left + assistantEdgeMargin;
                const maxLeft = Math.max(minLeft, viewport.left + viewport.width - width - assistantEdgeMargin);
                const minTop = viewport.top + assistantEdgeMargin;
                const maxTop = Math.max(minTop, assistantBottomLimit(viewport) - height);
                return {
                    left: Math.min(Math.max(left, minLeft), maxLeft),
                    top: Math.min(Math.max(top, minTop), maxTop),
                    viewport: viewport,
                    width: width
                };
            }

            function placeAssistant(left, top, persist) {
                const position = clampAssistantPosition(left, top);
                widget.style.setProperty('left', position.left + 'px', 'important');
                widget.style.setProperty('top', position.top + 'px', 'important');
                widget.style.setProperty('right', 'auto', 'important');
                widget.style.setProperty('bottom', 'auto', 'important');
                widget.classList.toggle('ai-tooltip-left', position.left + (position.width / 2) < position.viewport.left + (position.viewport.width / 2));
                if (persist) {
                    try {
                        localStorage.setItem(assistantPositionKey, JSON.stringify({left: position.left, top: position.top}));
                    } catch (error) {
                        // Local storage may be unavailable in private or restricted browsing modes.
                    }
                }
            }

            function resetAssistantPosition(askFirst) {
                if (askFirst && !window.confirm('Reset the TUGON AI button to its default position?')) {
                    return;
                }
                try {
                    localStorage.removeItem(assistantPositionKey);
                } catch (error) {
                    // Keep the visual reset even when storage is unavailable.
                }
                ['left', 'top', 'right', 'bottom'].forEach(function(property) {
                    widget.style.removeProperty(property);
                });
                widget.classList.remove('ai-tooltip-left');
            }

            function loadAssistantPosition() {
                if (!assistantPhoneView.matches) {
                    return;
                }
                try {
                    const saved = JSON.parse(localStorage.getItem(assistantPositionKey) || 'null');
                    if (saved && Number.isFinite(saved.left) && Number.isFinite(saved.top)) {
                        placeAssistant(saved.left, saved.top, true);
                    }
                } catch (error) {
                    try {
                        localStorage.removeItem(assistantPositionKey);
                    } catch (storageError) {
                        // Ignore storage restrictions and keep the default position.
                    }
                }
            }

            // Desktop Panel Position Clamping & Persistence
            function clampDesktopPanelPosition(left, top) {
                const margin = 20;
                const rect = panel.getBoundingClientRect();
                const width = rect.width || 440;
                const height = rect.height || 560;
                const winWidth = window.innerWidth;
                const winHeight = window.innerHeight;
                const minLeft = margin;
                const maxLeft = Math.max(minLeft, winWidth - width - margin);
                const minTop = margin;
                const maxTop = Math.max(minTop, winHeight - height - margin);
                return {
                    left: Math.min(Math.max(left, minLeft), maxLeft),
                    top: Math.min(Math.max(top, minTop), maxTop)
                };
            }

            function placeDesktopPanel(left, top, persist) {
                if (!isDesktopView.matches) return;
                const clamped = clampDesktopPanelPosition(left, top);
                panel.style.setProperty('left', clamped.left + 'px', 'important');
                panel.style.setProperty('top', clamped.top + 'px', 'important');
                panel.style.setProperty('right', 'auto', 'important');
                panel.style.setProperty('bottom', 'auto', 'important');
                if (persist) {
                    try {
                        sessionStorage.setItem(desktopPanelPositionKey, JSON.stringify({left: clamped.left, top: clamped.top}));
                    } catch (e) {}
                }
            }

            function loadDesktopPanelPosition() {
                if (!isDesktopView.matches) return;
                try {
                    const saved = JSON.parse(sessionStorage.getItem(desktopPanelPositionKey) || 'null');
                    if (saved && Number.isFinite(saved.left) && Number.isFinite(saved.top)) {
                        placeDesktopPanel(saved.left, saved.top, false);
                    }
                } catch (e) {}
            }

            function beginDesktopPanelDrag(event) {
                if (!isDesktopView.matches || (event.button !== undefined && event.button !== 0)) return;
                if (event.target.closest('button') || event.target.closest('a') || event.target.closest('input')) {
                    return;
                }
                const rect = panel.getBoundingClientRect();
                desktopPanelDragState = {
                    startX: event.clientX,
                    startY: event.clientY,
                    initialLeft: rect.left,
                    initialTop: rect.top,
                    latestX: event.clientX,
                    latestY: event.clientY,
                    pointerId: event.pointerId
                };
                panel.classList.add('is-panel-dragging');
                if (panelHeader && panelHeader.setPointerCapture && event.pointerId !== undefined) {
                    try { panelHeader.setPointerCapture(event.pointerId); } catch (e) {}
                }
                event.preventDefault();
            }

            function moveDesktopPanelDrag(event) {
                if (!desktopPanelDragState) return;
                event.preventDefault();
                desktopPanelDragState.latestX = event.clientX;
                desktopPanelDragState.latestY = event.clientY;
                if (!desktopPanelDragFrame) {
                    desktopPanelDragFrame = window.requestAnimationFrame(function() {
                        desktopPanelDragFrame = 0;
                        if (!desktopPanelDragState) return;
                        const deltaX = desktopPanelDragState.latestX - desktopPanelDragState.startX;
                        const deltaY = desktopPanelDragState.latestY - desktopPanelDragState.startY;
                        placeDesktopPanel(desktopPanelDragState.initialLeft + deltaX, desktopPanelDragState.initialTop + deltaY, false);
                    });
                }
            }

            function endDesktopPanelDrag(event) {
                if (!desktopPanelDragState) return;
                if (desktopPanelDragFrame) {
                    window.cancelAnimationFrame(desktopPanelDragFrame);
                    desktopPanelDragFrame = 0;
                }
                const deltaX = (event.clientX || desktopPanelDragState.latestX) - desktopPanelDragState.startX;
                const deltaY = (event.clientY || desktopPanelDragState.latestY) - desktopPanelDragState.startY;
                placeDesktopPanel(desktopPanelDragState.initialLeft + deltaX, desktopPanelDragState.initialTop + deltaY, true);
                panel.classList.remove('is-panel-dragging');
                if (panelHeader && panelHeader.releasePointerCapture && desktopPanelDragState.pointerId !== undefined) {
                    try { panelHeader.releasePointerCapture(desktopPanelDragState.pointerId); } catch (e) {}
                }
                desktopPanelDragState = null;
            }

            if (panelHeader) {
                if (window.PointerEvent) {
                    panelHeader.addEventListener('pointerdown', beginDesktopPanelDrag);
                    panelHeader.addEventListener('pointermove', moveDesktopPanelDrag);
                    panelHeader.addEventListener('pointerup', endDesktopPanelDrag);
                    panelHeader.addEventListener('pointercancel', endDesktopPanelDrag);
                } else {
                    panelHeader.addEventListener('mousedown', beginDesktopPanelDrag);
                    document.addEventListener('mousemove', moveDesktopPanelDrag);
                    document.addEventListener('mouseup', endDesktopPanelDrag);
                }
            }

            function assistantPoint(event) {
                if (event.touches && event.touches.length) {
                    return {x: event.touches[0].clientX, y: event.touches[0].clientY};
                }
                if (event.changedTouches && event.changedTouches.length) {
                    return {x: event.changedTouches[0].clientX, y: event.changedTouches[0].clientY};
                }
                return {x: event.clientX, y: event.clientY};
            }

            function beginAssistantDrag(event) {
                if (!assistantPhoneView.matches || widget.classList.contains('is-open') || (event.button !== undefined && event.button !== 0)) {
                    return;
                }
                const point = assistantPoint(event);
                const rect = trigger.getBoundingClientRect();
                assistantDragState = {
                    startX: point.x,
                    startY: point.y,
                    offsetX: point.x - rect.left,
                    offsetY: point.y - rect.top,
                    latestX: point.x,
                    latestY: point.y,
                    dragging: false,
                    longPressed: false,
                    pointerId: event.pointerId
                };
                window.clearTimeout(assistantLongPressTimer);
                assistantLongPressTimer = window.setTimeout(function() {
                    if (!assistantDragState || assistantDragState.dragging) {
                        return;
                    }
                    assistantDragState.longPressed = true;
                    suppressNextAssistantClick = true;
                    if (navigator.vibrate) navigator.vibrate(25);
                    resetAssistantPosition(true);
                }, 700);
            }

            function renderAssistantDrag() {
                assistantDragFrame = 0;
                if (!assistantDragState || !assistantDragState.dragging) {
                    return;
                }
                placeAssistant(
                    assistantDragState.latestX - assistantDragState.offsetX,
                    assistantDragState.latestY - assistantDragState.offsetY,
                    false
                );
            }

            function moveAssistantDrag(event) {
                if (!assistantDragState) {
                    return;
                }
                const point = assistantPoint(event);
                const deltaX = point.x - assistantDragState.startX;
                const deltaY = point.y - assistantDragState.startY;
                if (!assistantDragState.dragging && Math.hypot(deltaX, deltaY) >= assistantDragThreshold) {
                    assistantDragState.dragging = true;
                    window.clearTimeout(assistantLongPressTimer);
                    widget.classList.add('is-dragging');
                    trigger.setAttribute('aria-grabbed', 'true');
                }
                if (!assistantDragState.dragging) {
                    return;
                }
                event.preventDefault();
                assistantDragState.latestX = point.x;
                assistantDragState.latestY = point.y;
                if (!assistantDragFrame) {
                    assistantDragFrame = window.requestAnimationFrame(renderAssistantDrag);
                }
            }

            function endAssistantDrag(event) {
                if (!assistantDragState) {
                    return;
                }
                window.clearTimeout(assistantLongPressTimer);
                if (assistantDragState.dragging) {
                    const point = assistantPoint(event);
                    assistantDragState.latestX = point.x;
                    assistantDragState.latestY = point.y;
                    if (assistantDragFrame) {
                        window.cancelAnimationFrame(assistantDragFrame);
                        assistantDragFrame = 0;
                    }
                    placeAssistant(point.x - assistantDragState.offsetX, point.y - assistantDragState.offsetY, true);
                    suppressNextAssistantClick = true;
                }
                if (assistantDragState.longPressed) {
                    suppressNextAssistantClick = true;
                }
                widget.classList.remove('is-dragging');
                trigger.setAttribute('aria-grabbed', 'false');
                assistantDragState = null;
                window.setTimeout(function() {
                    suppressNextAssistantClick = false;
                }, 0);
            }

            function cancelAssistantDrag() {
                window.clearTimeout(assistantLongPressTimer);
                if (assistantDragFrame) window.cancelAnimationFrame(assistantDragFrame);
                assistantDragFrame = 0;
                assistantDragState = null;
                widget.classList.remove('is-dragging');
                trigger.setAttribute('aria-grabbed', 'false');
            }

            if (window.PointerEvent) {
                trigger.addEventListener('pointerdown', function(event) {
                    beginAssistantDrag(event);
                    if (assistantDragState && trigger.setPointerCapture) trigger.setPointerCapture(event.pointerId);
                });
                trigger.addEventListener('pointermove', moveAssistantDrag);
                trigger.addEventListener('pointerup', endAssistantDrag);
                trigger.addEventListener('pointercancel', cancelAssistantDrag);
            } else {
                trigger.addEventListener('touchstart', beginAssistantDrag, {passive: true});
                trigger.addEventListener('touchmove', moveAssistantDrag, {passive: false});
                trigger.addEventListener('touchend', endAssistantDrag);
                trigger.addEventListener('mousedown', beginAssistantDrag);
                document.addEventListener('mousemove', moveAssistantDrag);
                document.addEventListener('mouseup', endAssistantDrag);
            }

            window.requestAnimationFrame(loadAssistantPosition);
            window.addEventListener('resize', function() {
                if (assistantPhoneView.matches && widget.style.left) {
                    placeAssistant(parseFloat(widget.style.left), parseFloat(widget.style.top), true);
                } else if (isDesktopView.matches && panel.style.left) {
                    placeDesktopPanel(parseFloat(panel.style.left), parseFloat(panel.style.top), false);
                }
            });
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', function() {
                    if (assistantPhoneView.matches && widget.style.left) {
                        placeAssistant(parseFloat(widget.style.left), parseFloat(widget.style.top), false);
                    }
                });
            }

            // Set Assistant Open Function - Documents this helper's role in the parish management workflow.
            function setAssistantOpen(isOpen) {
                widget.classList.toggle('is-open', isOpen);
                document.body.classList.toggle('ai-chat-open', isOpen && window.matchMedia('(max-width: 599px)').matches);
                trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
                if (isOpen && isDesktopView.matches) {
                    loadDesktopPanelPosition();
                }
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
                status.innerHTML = isTyping ? '<span></span> Thinking...' : '<span></span> Online';
            }

            function setHealthStatus(state) {
                if (!status) return;
                status.classList.toggle('is-offline', state !== 'online');
                if (state === 'online') {
                    status.innerHTML = '<span></span> AI Online';
                } else if (state === 'model_unavailable') {
                    status.innerHTML = '<span></span> Model Offline';
                } else {
                    status.innerHTML = '<span></span> AI Offline';
                }
            }

            function checkAssistantHealth(force) {
                const now = Date.now();
                if (!force && now - lastHealthCheckAt < 60000) return;
                lastHealthCheckAt = now;
                fetch('<?php echo BASE_URL; ?>api/ai-health.php', {headers: {'Accept': 'application/json'}})
                    .then(function(response) { return response.json(); })
                    .then(function(data) { setHealthStatus(data.status || 'offline'); })
                    .catch(function() { setHealthStatus('offline'); });
            }

            function removeEmptyState() {
                const emptyState = document.getElementById('aiAssistantEmptyState');
                if (emptyState) {
                    emptyState.remove();
                }
            }

            function thinkingDelayFor(message) {
                return Math.min(1200, Math.max(600, String(message || '').length * 6));
            }

            function formatAssistantMarkdown(text) {
                if (!text) return '';
                const raw = String(text);
                let html = escapeHtml(raw);
                html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(match, label, url) {
                    const cleanUrl = url.trim();
                    return '<a href="' + cleanUrl + '" class="ai-action-btn"><i class="fas fa-arrow-up-right-from-square"></i> ' + label + '</a>';
                });
                const rawLines = html.split('\n');
                let result = [];
                let inList = false;
                for (let i = 0; i < rawLines.length; i++) {
                    const line = rawLines[i].trim();
                    if (/^(?:•|-|\*)\s+(.+)$/.test(line)) {
                        const content = line.replace(/^(?:•|-|\*)\s+/, '');
                        if (!inList) {
                            result.push('<ul class="ai-assistant-bullet-list">');
                            inList = true;
                        }
                        result.push('<li>' + content + '</li>');
                    } else if (/^\d+\.\s+(.+)$/.test(line)) {
                        const content = line.replace(/^\d+\.\s+/, '');
                        if (!inList) {
                            result.push('<ol class="ai-assistant-ordered-list">');
                            inList = true;
                        }
                        result.push('<li>' + content + '</li>');
                    } else {
                        if (inList) {
                            result.push('</ul>');
                            inList = false;
                        }
                        if (line.length > 0) {
                            result.push('<p>' + line + '</p>');
                        }
                    }
                }
                if (inList) {
                    result.push('</ul>');
                }
                return result.join('');
            }

            // Append Chat Message Function - Documents this helper's role in the parish management workflow.
            function appendChatMessage(type, title, message, sourcePrompt, stream, steps) {
                if (!liveAnswer) {
                    return null;
                }
                liveAnswer.hidden = false;
                removeEmptyState();
                const item = document.createElement('div');
                item.className = 'ai-assistant-chat-message ' + type;
                const stepList = Array.isArray(steps) && steps.length
                    ? '<ol class="ai-assistant-numbered-list">' + steps.map(function(step) { return '<li>' + escapeHtml(step) + '</li>'; }).join('') + '</ol>'
                    : '';
                const copyButton = type === 'assistant' ? '<button type="button" class="ai-assistant-copy" aria-label="Copy response text"><i class="fas fa-copy"></i> Copy</button>' : '';

                if (type === 'user') {
                    item.innerHTML = '<div class="ai-msg-bubble user-bubble"><p>' + escapeHtml(message) + '</p></div><div class="ai-assistant-message-meta"><span>' + currentTime() + '</span></div>';
                } else {
                    const formatted = formatAssistantMarkdown(message);
                    item.innerHTML = '<div class="ai-msg-header"><span class="ai-avatar-badge" aria-hidden="true"><i class="fas fa-robot"></i></span><strong>' + escapeHtml(title) + '</strong></div><div class="ai-msg-bubble assistant-bubble">' + formatted + '</div>' + stepList + '<div class="ai-assistant-message-meta"><span>' + currentTime() + '</span>' + copyButton + '</div>';
                }

                liveAnswer.appendChild(item);
                liveAnswer.scrollTop = liveAnswer.scrollHeight;
                if (liveInput) {
                    liveInput.focus();
                }
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
                item.innerHTML = '<div class="ai-msg-header"><span class="ai-avatar-badge" aria-hidden="true"><i class="fas fa-robot"></i></span><strong>' + escapeHtml(chatLabels.title) + '</strong></div><div class="ai-msg-bubble assistant-bubble ai-typing-bubble"><div class="ai-typing-dots" aria-label="' + escapeHtml(chatLabels.typing) + '"><span></span><span></span><span></span></div></div>';
                liveAnswer.appendChild(item);
                liveAnswer.scrollTop = liveAnswer.scrollHeight;
                return item;
            }

            function refreshAssistantCsrfToken() {
                return fetch('<?php echo BASE_URL; ?>api/csrf-token.php', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json'}
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data.success || !data.token) {
                        throw new Error(data.error || 'Unable to refresh the secure session token.');
                    }
                    assistantCsrfToken = data.token;
                    return assistantCsrfToken;
                });
            }

            function postAssistantMessage(message, retried) {
                return fetch('<?php echo BASE_URL; ?>api/ai-assistant.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': assistantCsrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({message: message, mode: 'chat', conversation: conversationHistory.slice(-8)})
                })
                .then(function(response) {
                    return response.json().then(function(data) {
                        if (response.status === 403 && !retried) {
                            return refreshAssistantCsrfToken().then(function() {
                                return postAssistantMessage(message, true);
                            });
                        }
                        return data;
                    });
                });
            }

            // Ask Live Assistant Function - Documents this helper's role in the parish management workflow.
            function askLiveAssistant(message) {
                if (!liveAnswer) {
                    return;
                }
                appendChatMessage('user', chatLabels.you, message);
                conversationHistory.push({role: 'user', content: message});
                const loading = appendTypingBubble();
                setTyping(true);
                if (liveSubmit) {
                    liveSubmit.disabled = true;
                }
                const startedAt = Date.now();

                postAssistantMessage(message, false)
                .then(function(data) {
                    const answer = data.success ? (data.reply || data.answer || chatLabels.noAnswer) : (data.error || data.message || chatLabels.unable);
                    const title = data.success && data.guidance && data.guidance.title ? data.guidance.title : chatLabels.title;
                    const remainingThinking = Math.max(0, thinkingDelayFor(answer) - (Date.now() - startedAt));
                    window.setTimeout(function() {
                        if (loading) {
                            loading.remove();
                        }
                        setTyping(false);
                        if (liveSubmit) {
                            liveSubmit.disabled = false;
                        }
                        appendChatMessage('assistant', title, answer, message, true, data.success && data.guidance && data.guidance.steps ? data.guidance.steps : []);
                        conversationHistory.push({role: 'assistant', content: answer});
                        if (!data.success && data.status) {
                            setHealthStatus(data.status);
                        }
                    }, remainingThinking);
                })
                .catch(function() {
                    const remainingThinking = Math.max(0, thinkingDelayFor(chatLabels.endpointError) - (Date.now() - startedAt));
                    window.setTimeout(function() {
                        if (loading) {
                            loading.remove();
                        }
                        setTyping(false);
                        setHealthStatus('offline');
                        if (liveSubmit) {
                            liveSubmit.disabled = false;
                        }
                        appendChatMessage('assistant', chatLabels.title, chatLabels.endpointError, message, true);
                    }, remainingThinking);
                });
            }

            checkAssistantHealth(true);

            trigger.addEventListener('click', function() {
                if (suppressNextAssistantClick) {
                    return;
                }
                setAssistantOpen(!widget.classList.contains('is-open'));
                if (widget.classList.contains('is-open')) {
                    checkAssistantHealth(false);
                }
            });

            document.querySelectorAll('[data-open-ai-chat], a[href*="ai-assistant.php"]').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    if (window.location.pathname.indexOf('ai-assistant.php') === -1) {
                        e.preventDefault();
                        setAssistantOpen(true);
                        checkAssistantHealth(false);
                        if (liveInput) {
                            liveInput.focus();
                        }
                    }
                });
            });

            close.addEventListener('click', function() {
                setAssistantOpen(false);
            });

            if (mobileBack) {
                mobileBack.addEventListener('click', function() {
                    setAssistantOpen(false);
                });
            }

            if (clear) {
                clear.addEventListener('click', function() {
                    liveAnswer.hidden = false;
                    liveAnswer.innerHTML = '<div class="ai-assistant-empty-state" id="aiAssistantEmptyState"><div class="ai-assistant-welcome-icon" aria-hidden="true"><i class="fas fa-robot"></i></div><div class="ai-assistant-greeting-bubble"><strong>Hello! I\'m TUGON AI.</strong><span>Need help? Ask me about certificate requirements, request status, Mass schedules, GCash verification, and parish services.</span></div><div class="ai-assistant-quick-heading">Quick Questions:</div><div class="ai-assistant-quick" aria-label="Suggested questions"><button type="button" data-ai-prompt="How do I request a certificate?"><span class="ai-chip-icon chip-cert"><i class="fas fa-file-lines"></i></span><span class="ai-chip-text">Request Certificate</span></button><button type="button" data-ai-prompt="What is the status of my request?"><span class="ai-chip-icon chip-track"><i class="fas fa-list-check"></i></span><span class="ai-chip-text">Track My Request</span></button><button type="button" data-ai-prompt="Where can I see the parish schedule?"><span class="ai-chip-icon chip-sched"><i class="fas fa-calendar-days"></i></span><span class="ai-chip-text">Parish Schedule</span></button><button type="button" data-ai-prompt="How do I request a blessing?"><span class="ai-chip-icon chip-bless"><i class="fas fa-hands-praying"></i></span><span class="ai-chip-text">Request Blessing</span></button><button type="button" data-ai-prompt="Where can I see parish announcements?"><span class="ai-chip-icon chip-news"><i class="fas fa-bullhorn"></i></span><span class="ai-chip-text">Announcements</span></button><button type="button" data-ai-prompt="How do I verify payment with GCash?"><span class="ai-chip-icon chip-pay"><i class="fas fa-receipt"></i></span><span class="ai-chip-text">GCash Payment</span></button></div></div>';
                    conversationHistory.length = 0;
                    if (liveInput) {
                        liveInput.focus();
                    }
                    if (liveSubmit) {
                        liveSubmit.disabled = true;
                    }
                });
            }

            if (minimize) {
                minimize.addEventListener('click', function() {
                    panel.classList.toggle('is-minimized');
                    minimize.innerHTML = panel.classList.contains('is-minimized') ? '<i class="fas fa-up-right-and-down-left-from-center"></i>' : '<i class="fas fa-minus"></i>';
                });
            }

            if (liveForm && liveInput) {
                liveInput.addEventListener('input', function() {
                    const hasText = liveInput.value.trim().length > 0;
                    if (liveSubmit) {
                        liveSubmit.disabled = !hasText;
                    }
                });

                liveForm.addEventListener('submit', function(event) {
                    event.preventDefault();
                    const message = liveInput.value.trim();
                    if (!message) {
                        return;
                    }
                    liveInput.value = '';
                    if (liveSubmit) {
                        liveSubmit.disabled = true;
                    }
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
                const aiOpener = event.target.closest('[data-open-ai-chat], #sidebarAiAssistantLink, #adminSidebarAiAssistantLink, .nav-item-ai');
                if (aiOpener) {
                    if (widget && !window.location.pathname.endsWith('ai-assistant.php')) {
                        event.preventDefault();
                        event.stopPropagation();
                        // If mobile sidebar was open, close it
                        const openSidebar = document.querySelector('.user-sidebar.open, .admin-sidebar.open');
                        if (openSidebar) {
                            openSidebar.classList.remove('open');
                            document.body.classList.remove('sidebar-open');
                        }
                        setAssistantOpen(true);
                        checkAssistantHealth(false);
                        if (liveInput) {
                            setTimeout(function() { liveInput.focus(); }, 120);
                        }
                        return;
                    }
                }

                const quickPrompt = event.target.closest('[data-ai-prompt]');
                if (quickPrompt) {
                    const prompt = quickPrompt.getAttribute('data-ai-prompt');
                    if (prompt) {
                        setAssistantOpen(true);
                        checkAssistantHealth(false);
                        askLiveAssistant(prompt);
                    }
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
    <!-- Re-apply after page/footer inline themes so WCAG contrast rules win deterministically. -->
    <link rel="stylesheet" href="../assets/css/accessibility.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/accessibility.css'); ?>">
    <script src="../assets/js/accessibility.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/accessibility.js'); ?>"></script>
</body>
</html>
