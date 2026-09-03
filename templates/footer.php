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
           TUGON PARISH GUIDE AI CHATBOT — SACRED CATHOLIC CHURCH REDESIGN
           Palette: Cathedral Green (#344536), Warm Gold (#C9A646),
           Warm Ivory (#F8F5ED), Soft White Surface (#FFFDF8), Charcoal (#30342F)
           ══════════════════════════════════════════════════════════════════ */
        :root {
            --tg-primary: #344536;
            --tg-primary-dark: #243326;
            --tg-primary-deep: #1B261D;
            --tg-gold: #C9A646;
            --tg-gold-hover: #B89332;
            --tg-gold-soft: rgba(201, 166, 70, 0.12);
            --tg-gold-border: #E7DFC9;
            --tg-bg-cream: #F8F5ED;
            --tg-bg-warm: #FAF7F0;
            --tg-surface: #FFFDF8;
            --tg-text: #30342F;
            --tg-text-muted: #7D8078;
            --tg-shadow-sm: 0 2px 8px rgba(52, 69, 54, 0.08);
            --tg-shadow-md: 0 8px 24px rgba(52, 69, 54, 0.12);
            --tg-shadow-lg: 0 18px 48px rgba(34, 45, 36, 0.22), 0 4px 16px rgba(201, 166, 70, 0.1);
        }

        html body .ai-assistant-widget,
        html body.user-area .ai-assistant-widget {
            position: fixed !important;
            bottom: 24px;
            right: 24px;
            z-index: 99999 !important;
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            pointer-events: auto !important;
            width: auto !important;
            height: auto !important;
            margin: 0 !important;
            padding: 0 !important;
            font-family: "Plus Jakarta Sans", "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
            touch-action: none;
        }

        /* ── Floating Launcher Trigger (Closed State) ───────────────── */
        html body .ai-assistant-trigger,
        html body.user-area .ai-assistant-trigger {
            position: relative !important;
            width: 58px !important;
            height: 58px !important;
            min-width: 58px !important;
            min-height: 58px !important;
            border-radius: 50% !important;
            background: linear-gradient(145deg, #344536 0%, #223023 100%) !important;
            color: #FFFDF8 !important;
            border: 2px solid #C9A646 !important;
            box-shadow: 0 10px 26px rgba(34, 48, 35, 0.38), 0 2px 8px rgba(201, 166, 70, 0.3) !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 1.4rem !important;
            cursor: grab !important;
            visibility: visible !important;
            opacity: 1 !important;
            pointer-events: auto !important;
            transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.22s ease, border-color 0.22s ease !important;
            text-decoration: none !important;
            touch-action: none !important;
            user-select: none !important;
            -webkit-user-select: none !important;
        }
        html body .ai-assistant-trigger:hover,
        html body.user-area .ai-assistant-trigger:hover {
            transform: scale(1.06) translateY(-2px) !important;
            box-shadow: 0 14px 32px rgba(34, 48, 35, 0.45), 0 4px 14px rgba(201, 166, 70, 0.45) !important;
            border-color: #E2CE98 !important;
            color: #FFFFFF !important;
        }
        html body .ai-assistant-widget.is-dragging .ai-assistant-trigger {
            cursor: grabbing !important;
            transform: scale(1.08) !important;
            box-shadow: 0 16px 36px rgba(34, 48, 35, 0.5), 0 6px 18px rgba(201, 166, 70, 0.5) !important;
            transition: none !important;
        }
        html body .ai-assistant-icon {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            color: #FFFDF8 !important;
            position: relative !important;
            transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.18s ease !important;
        }
        html body .ai-assistant-close-icon {
            display: none !important;
            align-items: center !important;
            justify-content: center !important;
            color: #FAF6ED !important;
            position: relative !important;
            font-size: 1.35rem !important;
            transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.18s ease !important;
        }
        html body .ai-assistant-icon i,
        html body .ai-assistant-close-icon i {
            font-size: 1.35rem !important;
            color: #FAF6ED !important;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.25));
        }
        html body .ai-assistant-online-indicator {
            position: absolute !important;
            top: 2px !important;
            right: 2px !important;
            width: 12px !important;
            height: 12px !important;
            border-radius: 50% !important;
            background: #22C55E !important;
            border: 2px solid #223023 !important;
            box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.45) !important;
            animation: aiPulseOnline 2.4s infinite !important;
        }
        @keyframes aiPulseOnline {
            0%, 100% { transform: scale(1); opacity: 1; box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.45); }
            50% { transform: scale(1.15); opacity: 0.9; box-shadow: 0 0 0 4.5px rgba(34, 197, 94, 0.15); }
        }
        html body .ai-assistant-chathead-label {
            position: absolute !important;
            bottom: -9px !important;
            left: 50% !important;
            transform: translateX(-50%) !important;
            background: #223023 !important;
            color: #F8F5ED !important;
            border: 1px solid #C9A646 !important;
            padding: 2px 7px !important;
            border-radius: 999px !important;
            font-size: 0.64rem !important;
            font-weight: 700 !important;
            letter-spacing: 0.5px !important;
            white-space: nowrap !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25) !important;
            pointer-events: none !important;
        }

        /* ── Open State for Floating Launcher (Stays visible & morphs to active Close button) ── */
        html body .ai-assistant-widget.is-open .ai-assistant-trigger {
            display: inline-flex !important;
            background: linear-gradient(145deg, #28372A 0%, #1A241C 100%) !important;
            border-color: #E2CE98 !important;
            box-shadow: 0 10px 28px rgba(27, 38, 29, 0.45), 0 0 16px rgba(201, 166, 70, 0.35) !important;
            transform: scale(1) !important;
        }
        html body .ai-assistant-widget.is-open .ai-assistant-trigger:hover {
            transform: scale(1.08) !important;
            box-shadow: 0 14px 32px rgba(27, 38, 29, 0.55), 0 0 20px rgba(201, 166, 70, 0.5) !important;
            border-color: #F8F5ED !important;
        }
        html body .ai-assistant-widget.is-open .ai-assistant-trigger .ai-assistant-icon {
            display: none !important;
        }
        html body .ai-assistant-widget.is-open .ai-assistant-trigger .ai-assistant-close-icon {
            display: inline-flex !important;
            animation: aiSpinIn 0.22s cubic-bezier(0.16, 1, 0.3, 1) both !important;
        }
        html body .ai-assistant-widget.is-open .ai-assistant-trigger .ai-assistant-chathead-label {
            display: none !important;
        }
        html body .ai-assistant-widget.is-open .ai-assistant-trigger .ai-assistant-online-indicator {
            display: none !important;
        }
        @keyframes aiSpinIn {
            from { transform: rotate(-90deg) scale(0.6); opacity: 0; }
            to { transform: rotate(0deg) scale(1); opacity: 1; }
        }

        /* ── Main Chat Panel Window (Warm Catholic Sanctuary Aesthetic) ──── */
        html body .ai-assistant-panel,
        html body.user-area .ai-assistant-panel {
            position: fixed !important;
            bottom: 92px !important;
            right: 24px !important;
            width: 400px !important;
            max-width: calc(100vw - 32px) !important;
            height: 560px !important;
            max-height: calc(100vh - 110px) !important;
            border-radius: 20px !important;
            background: #FAF7F0 !important;
            background-image:
                radial-gradient(circle at 100% 0%, rgba(201, 166, 70, 0.07) 0%, transparent 45%),
                radial-gradient(circle at 0% 100%, rgba(52, 69, 54, 0.05) 0%, transparent 45%),
                url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M30 6 C24 18 16 28 16 48 L44 48 C44 28 36 18 30 6 Z' fill='none' stroke='%23C9A646' stroke-width='0.75' stroke-opacity='0.035'/%3E%3Cpath d='M30 16 L30 38 M22 25 L38 25' stroke='%23344536' stroke-width='0.65' stroke-opacity='0.028'/%3E%3C/svg%3E") !important;
            border: 1px solid #E7DFC9 !important;
            box-shadow: 0 18px 48px rgba(34, 45, 36, 0.22), 0 4px 16px rgba(201, 166, 70, 0.1) !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
            z-index: 99999 !important;
            transform: translateY(14px) scale(0.96) !important;
            opacity: 0 !important;
            pointer-events: none !important;
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.22s ease !important;
        }
        html body .ai-assistant-widget.is-open .ai-assistant-panel {
            display: flex !important;
            opacity: 1 !important;
            pointer-events: auto !important;
            transform: translateY(0) scale(1) !important;
        }

        /* ── Header ─────────────────────────────────────────────────── */
        html body .ai-assistant-panel-header,
        html body.user-area .ai-assistant-panel-header {
            background: linear-gradient(135deg, #344536 0%, #263628 100%) !important;
            color: #FFFDF8 !important;
            border-bottom: 1.5px solid #C9A646 !important;
            padding: 12px 16px !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            box-shadow: 0 2px 8px rgba(34, 45, 36, 0.25) !important;
            user-select: none !important;
            cursor: grab !important;
        }
        html body .ai-assistant-panel.is-panel-dragging .ai-assistant-panel-header {
            cursor: grabbing !important;
        }
        html body .ai-assistant-mobile-back {
            display: none !important;
            background: transparent !important;
            border: 0 !important;
            color: #F8F5ED !important;
            font-size: 1.05rem !important;
            padding: 0 4px !important;
            cursor: pointer !important;
            transition: color 0.15s ease !important;
        }
        html body .ai-assistant-mobile-back:hover {
            color: #C9A646 !important;
        }
        html body .ai-assistant-panel-mark,
        html body.user-area .ai-assistant-panel-mark {
            width: 36px !important;
            height: 36px !important;
            border-radius: 10px !important;
            background: linear-gradient(135deg, rgba(201, 166, 70, 0.25) 0%, rgba(201, 166, 70, 0.1) 100%) !important;
            border: 1.5px solid #C9A646 !important;
            color: #FFFDF8 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 1.15rem !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2), 0 0 8px rgba(201, 166, 70, 0.25) !important;
            flex-shrink: 0 !important;
        }
        html body .ai-assistant-panel-identity {
            display: flex !important;
            flex-direction: column !important;
            flex: 1 1 auto !important;
            min-width: 0 !important;
        }
        html body .ai-assistant-panel-identity strong {
            font-family: "Playfair Display", "Cinzel", Georgia, serif !important;
            font-size: 1.05rem !important;
            color: #FFFDF8 !important;
            font-weight: 700 !important;
            line-height: 1.2 !important;
            letter-spacing: 0.3px !important;
        }
        html body .ai-assistant-panel-identity span {
            font-size: 0.72rem !important;
            color: #D8CEB8 !important;
            font-weight: 500 !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 5px !important;
            margin-top: 1px !important;
        }
        html body .ai-assistant-status-dot {
            width: 7px !important;
            height: 7px !important;
            border-radius: 50% !important;
            background: #22C55E !important;
            box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.35) !important;
            display: inline-block !important;
        }
        html body .ai-assistant-tool,
        html body .ai-assistant-close {
            background: rgba(255, 255, 255, 0.08) !important;
            border: 1px solid rgba(201, 166, 70, 0.35) !important;
            color: #E8DFC8 !important;
            width: 28px !important;
            height: 28px !important;
            border-radius: 7px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            font-size: 0.8rem !important;
            transition: all 0.16s ease !important;
        }
        html body .ai-assistant-tool:hover,
        html body .ai-assistant-close:hover {
            color: #FFFFFF !important;
            background: rgba(201, 166, 70, 0.28) !important;
            border-color: #C9A646 !important;
            transform: scale(1.06) !important;
        }

        /* ── Panel Body & Scrollable Area ───────────────────────────── */
        html body .ai-assistant-panel-body {
            display: flex !important;
            flex-direction: column !important;
            flex: 1 1 auto !important;
            height: calc(100% - 62px) !important;
            overflow: hidden !important;
            background: transparent !important;
        }
        html body .ai-assistant-live-answer,
        html body.user-area .ai-assistant-live-answer {
            flex: 1 1 auto !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding: 16px 14px !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 12px !important;
            scrollbar-width: thin;
            scrollbar-color: rgba(201, 166, 70, 0.35) transparent;
            background: transparent !important;
        }
        html body .ai-assistant-live-answer::-webkit-scrollbar {
            width: 5px !important;
        }
        html body .ai-assistant-live-answer::-webkit-scrollbar-track {
            background: transparent !important;
        }
        html body .ai-assistant-live-answer::-webkit-scrollbar-thumb {
            background: rgba(201, 166, 70, 0.35) !important;
            border-radius: 10px !important;
        }
        html body .ai-assistant-live-answer::-webkit-scrollbar-thumb:hover {
            background: rgba(201, 166, 70, 0.65) !important;
        }

        /* ── Empty State / Sacred Welcome Card ─────────────────────── */
        html body .ai-assistant-empty-state {
            display: flex !important;
            flex-direction: column !important;
            gap: 12px !important;
            margin-top: 4px !important;
            animation: aiFadeInUp 0.25s ease both !important;
        }
        html body .ai-assistant-welcome-icon {
            width: 44px !important;
            height: 44px !important;
            margin: 2px auto 4px !important;
            border-radius: 12px !important;
            background: linear-gradient(135deg, #344536 0%, #263628 100%) !important;
            border: 1.5px solid #C9A646 !important;
            color: #E8D8B5 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 1.35rem !important;
            box-shadow: 0 4px 14px rgba(52, 69, 54, 0.15) !important;
        }
        html body .ai-assistant-greeting-bubble {
            background: #FFFDF8 !important;
            border: 1px solid #E7DFC9 !important;
            border-left: 3.5px solid #C9A646 !important;
            border-radius: 14px !important;
            padding: 14px 16px !important;
            box-shadow: 0 3px 12px rgba(52, 69, 54, 0.05) !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 6px !important;
        }
        html body .ai-assistant-greeting-bubble strong {
            font-family: "Playfair Display", Georgia, serif !important;
            font-size: 0.96rem !important;
            font-weight: 700 !important;
            color: #344536 !important;
            letter-spacing: 0.2px !important;
        }
        html body .ai-assistant-greeting-bubble span {
            font-size: 0.84rem !important;
            color: #4A4E48 !important;
            line-height: 1.55 !important;
        }
        html body .ai-assistant-quick-prompts {
            display: flex !important;
            flex-wrap: wrap !important;
            gap: 6px !important;
            margin-top: 2px !important;
        }
        html body .ai-quick-chip {
            background: #FFFDF8 !important;
            border: 1px solid #E7DFC9 !important;
            border-radius: 999px !important;
            padding: 6px 12px !important;
            font-size: 0.78rem !important;
            font-weight: 600 !important;
            color: #344536 !important;
            cursor: pointer !important;
            transition: all 0.16s ease !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 5px !important;
            box-shadow: 0 1px 4px rgba(52, 69, 54, 0.04) !important;
        }
        html body .ai-quick-chip:hover {
            background: #344536 !important;
            border-color: #344536 !important;
            color: #FFFFFF !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 3px 8px rgba(52, 69, 54, 0.18) !important;
        }

        /* ── Chat Messages (AI vs User) ─────────────────────────────── */
        html body .ai-assistant-chat-message {
            display: flex !important;
            flex-direction: column !important;
            gap: 3px !important;
            animation: aiFadeInUp 0.2s ease both !important;
        }
        html body .ai-assistant-chat-message.user {
            align-self: flex-end !important;
            max-width: 82% !important;
        }
        html body .ai-assistant-chat-message.assistant {
            align-self: flex-start !important;
            max-width: 90% !important;
        }
        html body .ai-msg-header {
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
            margin-bottom: 2px !important;
        }
        html body .ai-avatar-badge {
            width: 20px !important;
            height: 20px !important;
            border-radius: 6px !important;
            background: #344536 !important;
            border: 1px solid #C9A646 !important;
            color: #E8D8B5 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 10px !important;
        }
        html body .ai-msg-header strong {
            font-family: "Playfair Display", Georgia, serif !important;
            font-size: 0.78rem !important;
            font-weight: 700 !important;
            color: #344536 !important;
            letter-spacing: 0.2px !important;
        }
        html body .ai-msg-bubble {
            padding: 11px 14px !important;
            font-size: 0.86rem !important;
            line-height: 1.55 !important;
            word-break: break-word !important;
        }
        html body .ai-msg-bubble.user-bubble {
            background: linear-gradient(135deg, #344536 0%, #263628 100%) !important;
            color: #FFFFFF !important;
            border: 1px solid rgba(201, 166, 70, 0.3) !important;
            border-radius: 16px 16px 4px 16px !important;
            box-shadow: 0 2px 8px rgba(52, 69, 54, 0.15) !important;
        }
        html body .ai-msg-bubble.user-bubble p {
            margin: 0 !important;
            color: #FFFFFF !important;
            font-weight: 500 !important;
            font-size: 0.86rem !important;
            line-height: 1.5 !important;
        }
        html body .ai-msg-bubble.assistant-bubble {
            background: #FFFDF8 !important;
            color: #30342F !important;
            border: 1px solid #E7DFC9 !important;
            border-left: 3px solid #C9A646 !important;
            border-radius: 4px 16px 16px 16px !important;
            box-shadow: 0 2px 10px rgba(52, 69, 54, 0.05) !important;
        }
        html body .ai-msg-bubble.assistant-bubble p {
            margin: 0 0 6px 0 !important;
            color: #30342F !important;
            font-size: 0.86rem !important;
            line-height: 1.6 !important;
        }
        html body .ai-msg-bubble.assistant-bubble p:last-child {
            margin-bottom: 0 !important;
        }
        html body .ai-msg-bubble.assistant-bubble strong {
            color: #222621 !important;
            font-weight: 700 !important;
        }
        html body .ai-assistant-bullet-list,
        html body .ai-assistant-ordered-list,
        html body .ai-assistant-numbered-list {
            margin: 6px 0 !important;
            padding-left: 18px !important;
        }
        html body .ai-assistant-bullet-list li,
        html body .ai-assistant-ordered-list li,
        html body .ai-assistant-numbered-list li {
            margin-bottom: 4px !important;
            font-size: 0.84rem !important;
            color: #3E433C !important;
            line-height: 1.5 !important;
        }
        html body .ai-action-btn {
            display: inline-flex !important;
            align-items: center !important;
            gap: 5px !important;
            padding: 5px 11px !important;
            background: #F8F5ED !important;
            border: 1px solid #C9A646 !important;
            border-radius: 6px !important;
            color: #344536 !important;
            font-weight: 600 !important;
            font-size: 11.5px !important;
            text-decoration: none !important;
            margin-top: 6px !important;
            transition: all 0.15s ease !important;
        }
        html body .ai-action-btn:hover {
            background: #344536 !important;
            color: #FFFFFF !important;
            border-color: #344536 !important;
            transform: translateY(-1px) !important;
        }
        html body .ai-assistant-message-meta {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            font-size: 10.5px !important;
            color: #8C9086 !important;
            padding: 1px 2px !important;
        }
        html body .ai-assistant-chat-message.user .ai-assistant-message-meta {
            justify-content: flex-end !important;
        }
        html body .ai-assistant-copy {
            background: transparent !important;
            border: none !important;
            color: #8C9086 !important;
            font-size: 11px !important;
            cursor: pointer !important;
            padding: 2px 6px !important;
            border-radius: 4px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 4px !important;
            transition: all 0.15s ease !important;
            font-weight: 500 !important;
        }
        html body .ai-assistant-copy:hover {
            color: #C9A646 !important;
            background: rgba(201, 166, 70, 0.12) !important;
        }
        html body .ai-assistant-copy.is-copied {
            color: #22C55E !important;
        }

        /* ── Typing Indicator Dots ──────────────────────────────────── */
        html body .ai-typing-bubble {
            padding: 11px 15px !important;
            display: inline-flex !important;
            align-items: center !important;
        }
        html body .ai-typing-dots {
            display: flex !important;
            align-items: center !important;
            gap: 5px !important;
        }
        html body .ai-typing-dots span {
            width: 7px !important;
            height: 7px !important;
            border-radius: 50% !important;
            background: #C9A646 !important;
            animation: aiTypingBounce 1.3s infinite ease-in-out both !important;
        }
        html body .ai-typing-dots span:nth-child(1) { animation-delay: -0.32s !important; }
        html body .ai-typing-dots span:nth-child(2) { animation-delay: -0.16s !important; }
        @keyframes aiTypingBounce {
            0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
            40% { transform: scale(1.1); opacity: 1; }
        }

        /* ── Input Form (Seamless Cathedral Pill) ────────────────────── */
        html body .ai-assistant-live-form,
        html body.user-area .ai-assistant-live-form {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            padding: 12px 14px !important;
            background: #FFFDF8 !important;
            border-top: 1px solid #E7DFC9 !important;
        }
        html body .ai-assistant-search,
        html body.user-area .ai-assistant-search {
            flex: 1 1 auto !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            background: #FAF7F0 !important;
            border: 1.5px solid #E7DFC9 !important;
            border-radius: 22px !important;
            padding: 4px 12px !important;
            transition: all 0.18s ease !important;
        }
        html body .ai-assistant-search:focus-within,
        html body.user-area .ai-assistant-search:focus-within {
            background: #FFFFFF !important;
            border-color: #C9A646 !important;
            box-shadow: 0 0 0 3px rgba(201, 166, 70, 0.2) !important;
        }
        html body .ai-assistant-search i {
            color: #8C9086 !important;
            font-size: 13px !important;
        }
        html body .ai-assistant-search textarea {
            width: 100% !important;
            border: none !important;
            background: transparent !important;
            outline: none !important;
            font-size: 0.86rem !important;
            color: #30342F !important;
            line-height: 1.4 !important;
            padding: 6px 0 !important;
            resize: none !important;
            max-height: 80px !important;
            font-family: inherit !important;
        }
        html body .ai-assistant-search textarea::placeholder {
            color: #9C9F98 !important;
        }
        html body .ai-assistant-live-form button[type="submit"],
        html body.user-area .ai-assistant-live-form button[type="submit"] {
            width: 38px !important;
            height: 38px !important;
            min-width: 38px !important;
            border-radius: 50% !important;
            border: 1px solid #C9A646 !important;
            background: linear-gradient(135deg, #344536 0%, #253527 100%) !important;
            color: #F8F5ED !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            box-shadow: 0 3px 10px rgba(52, 69, 54, 0.25) !important;
            transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease, background 0.18s ease !important;
            font-size: 13px !important;
        }
        html body .ai-assistant-live-form button[type="submit"]:hover:not(:disabled),
        html body.user-area .ai-assistant-live-form button[type="submit"]:hover:not(:disabled) {
            transform: scale(1.08) !important;
            box-shadow: 0 4px 14px rgba(52, 69, 54, 0.35) !important;
            background: linear-gradient(135deg, #C9A646 0%, #B89332 100%) !important;
            color: #FFFFFF !important;
        }
        html body .ai-assistant-live-form button[type="submit"]:disabled,
        html body.user-area .ai-assistant-live-form button[type="submit"]:disabled {
            opacity: 0.45 !important;
            cursor: not-allowed !important;
            transform: none !important;
            background: #7D8078 !important;
            border-color: #7D8078 !important;
            color: #E8DFC8 !important;
            box-shadow: none !important;
        }

        /* ── Animations ─────────────────────────────────────────────── */
        @keyframes aiFadeInUp {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Minimized & Responsive ─────────────────────────────────── */
        html body .ai-assistant-panel.is-minimized {
            height: 56px !important;
            max-height: 56px !important;
            overflow: hidden !important;
        }
        html body .ai-assistant-panel.is-minimized .ai-assistant-panel-body {
            display: none !important;
        }

        @media (max-width: 599px) {
            html body .ai-assistant-widget {
                bottom: calc(16px + env(safe-area-inset-bottom));
                right: 16px;
            }
            html body .ai-assistant-widget.is-open .ai-assistant-panel {
                inset: 0 !important;
                width: 100vw !important;
                height: 100dvh !important;
                max-width: none !important;
                max-height: none !important;
                border-radius: 0 !important;
                border: 0 !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
            }
            html body .ai-assistant-widget.is-open .ai-assistant-trigger {
                display: none !important;
            }
            html body .ai-assistant-mobile-back {
                display: inline-flex !important;
            }
        }
    </style>

    <?php if (isLoggedIn()): ?>
    <div class="ai-assistant-widget" id="aiAssistantWidget">
        <button class="ai-assistant-trigger" type="button" id="aiAssistantTrigger" aria-label="Open TUGON Parish Guide" aria-expanded="false" title="Need help? Chat with TUGON Parish Guide">
            <span class="ai-assistant-online-indicator" aria-hidden="true"></span>
            <span class="ai-assistant-icon" aria-hidden="true">
                <i class="fas fa-church"></i>
            </span>
            <span class="ai-assistant-close-icon" aria-hidden="true">
                <i class="fas fa-xmark"></i>
            </span>
            <span class="ai-assistant-chathead-label">PARISH GUIDE</span>
        </button>
        <section class="ai-assistant-panel" id="aiAssistantPanel" aria-hidden="true" role="dialog" aria-label="TUGON Parish Guide">
            <div class="ai-assistant-panel-header">
                <button class="ai-assistant-mobile-back" type="button" id="aiAssistantMobileBack" aria-label="Back to previous screen">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </button>
                <div class="ai-assistant-panel-mark" aria-hidden="true">
                    <i class="fas fa-church"></i>
                </div>
                <div class="ai-assistant-panel-identity">
                    <strong>TUGON Parish Guide</strong>
                    <span id="aiAssistantStatus"><span class="ai-assistant-status-dot" aria-hidden="true"></span> Online &amp; Ready</span>
                </div>
                <button class="ai-assistant-tool" type="button" id="aiAssistantClear" aria-label="Clear conversation" title="Clear conversation">
                    <i class="fas fa-rotate-left"></i>
                </button>
                <button class="ai-assistant-tool" type="button" id="aiAssistantMinimize" aria-label="Minimize chat" title="Minimize chat">
                    <i class="fas fa-minus"></i>
                </button>
                <button class="ai-assistant-close" type="button" id="aiAssistantClose" aria-label="<?php echo e(t('chatbot.close_label', 'Close AI assistant')); ?>" title="Close chat">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div class="ai-assistant-panel-body">
                <div class="ai-assistant-live-answer" id="aiAssistantLiveAnswer">
                    <div class="ai-assistant-empty-state" id="aiAssistantEmptyState">
                        <div class="ai-assistant-welcome-icon" aria-hidden="true">
                            <i class="fas fa-cross"></i>
                        </div>
                        <div class="ai-assistant-greeting-bubble">
                            <strong id="aiAssistantWelcomeHeading">Good day, and peace be with you!</strong>
                            <span id="aiAssistantWelcomeSub">I am your TUGON Parish Guide. Feel free to ask about certificate requirements, Mass schedules, sacramental guidelines, or request tracking.</span>
                        </div>
                        <div class="ai-assistant-quick-prompts" id="aiAssistantQuickPrompts">
                            <button type="button" class="ai-quick-chip" data-ai-prompt="What are the mass schedules?">⛪ Mass Schedules</button>
                            <button type="button" class="ai-quick-chip" data-ai-prompt="How do I request a Baptismal Certificate?">📜 Baptism Certificate</button>
                            <button type="button" class="ai-quick-chip" data-ai-prompt="What are the requirements for Matrimony/Wedding?">💍 Wedding Guidelines</button>
                            <button type="button" class="ai-quick-chip" data-ai-prompt="How do I track my submitted request?">🔍 Track My Request</button>
                        </div>
                    </div>
                </div>
                <form class="ai-assistant-live-form" id="aiAssistantLiveForm">
                    <label class="ai-assistant-search" for="aiAssistantLiveInput">
                        <i class="fas fa-comment-dots" aria-hidden="true"></i>
                        <textarea id="aiAssistantLiveInput" rows="1" maxlength="2000" data-no-autocomplete="true" placeholder="Ask about certificates, mass schedules, requests..."></textarea>
                    </label>
                    <button type="submit" id="aiAssistantSendBtn" aria-label="<?php echo e(t('chatbot.send', 'Send')); ?>" title="Send message" disabled><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
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
    <?php if (isLoggedIn()): ?>
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
            const panelHeader = panel ? panel.querySelector('.ai-assistant-panel-header') : null;

            if (!widget || !trigger || !panel || !close) {
                return;
            }

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

            const assistantPositionKey = 'tugonAiFabPosition:v1:<?php echo intval($_SESSION['user_id'] ?? 0); ?>';
            const desktopPanelPositionKey = 'tugonAiPanelDesktopPos:v1:<?php echo intval($_SESSION['user_id'] ?? 0); ?>';
            const isDesktopView = window.matchMedia('(min-width: 600px)');
            const DRAG_THRESHOLD = 6;
            const EDGE_MARGIN = 12;

            let isDragging = false;
            let hasMovedPastThreshold = false;
            let justDragged = false;
            let dragStartX = 0;
            let dragStartY = 0;
            let initialWidgetLeft = 0;
            let initialWidgetTop = 0;
            let currentClampedLeft = 0;
            let currentClampedTop = 0;
            let activePointerId = null;
            let dragRaf = 0;

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
                let limit = viewport.top + viewport.height - EDGE_MARGIN;
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
                        limit = Math.min(limit, rect.top - EDGE_MARGIN);
                    }
                });
                return limit;
            }

            function clampAssistantPosition(left, top) {
                const viewport = assistantViewport();
                const rect = trigger.getBoundingClientRect();
                const width = rect.width || 58;
                const height = rect.height || 58;
                const minLeft = viewport.left + EDGE_MARGIN;
                const maxLeft = Math.max(minLeft, viewport.left + viewport.width - width - EDGE_MARGIN);
                const minTop = viewport.top + EDGE_MARGIN;
                const maxTop = Math.max(minTop, assistantBottomLimit(viewport) - height);
                return {
                    left: Math.min(Math.max(left, minLeft), maxLeft),
                    top: Math.min(Math.max(top, minTop), maxTop),
                    viewport: viewport,
                    width: width,
                    height: height
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
                    } catch (error) {}
                }
            }

            function loadAssistantPosition() {
                try {
                    const saved = JSON.parse(localStorage.getItem(assistantPositionKey) || 'null');
                    if (saved && Number.isFinite(saved.left) && Number.isFinite(saved.top)) {
                        placeAssistant(saved.left, saved.top, false);
                    }
                } catch (error) {}
            }

            function assistantPoint(event) {
                if (event.touches && event.touches.length) {
                    return { x: event.touches[0].clientX, y: event.touches[0].clientY };
                }
                if (event.changedTouches && event.changedTouches.length) {
                    return { x: event.changedTouches[0].clientX, y: event.changedTouches[0].clientY };
                }
                return { x: event.clientX, y: event.clientY };
            }

            // ── Floating Launcher Drag Handling (Touch, Mouse, Pointer) ──
            function onTriggerPointerDown(event) {
                if (event.button !== undefined && event.button !== 0) return;
                if (widget.classList.contains('is-open')) return;

                const point = assistantPoint(event);
                const rect = widget.getBoundingClientRect();

                isDragging = false;
                hasMovedPastThreshold = false;
                activePointerId = event.pointerId !== undefined ? event.pointerId : null;
                dragStartX = point.x;
                dragStartY = point.y;
                initialWidgetLeft = rect.left;
                initialWidgetTop = rect.top;
                currentClampedLeft = rect.left;
                currentClampedTop = rect.top;

                if (trigger.setPointerCapture && activePointerId !== null) {
                    try { trigger.setPointerCapture(activePointerId); } catch (e) {}
                }

                if (window.PointerEvent) {
                    window.addEventListener('pointermove', onTriggerPointerMove, { passive: false });
                    window.addEventListener('pointerup', onTriggerPointerUp);
                    window.addEventListener('pointercancel', onTriggerPointerCancel);
                } else {
                    document.addEventListener('mousemove', onTriggerPointerMove);
                    document.addEventListener('mouseup', onTriggerPointerUp);
                    document.addEventListener('touchmove', onTriggerPointerMove, { passive: false });
                    document.addEventListener('touchend', onTriggerPointerUp);
                    document.addEventListener('touchcancel', onTriggerPointerCancel);
                }
            }

            function onTriggerPointerMove(event) {
                if (activePointerId !== null && event.pointerId !== undefined && event.pointerId !== activePointerId) {
                    return;
                }
                const point = assistantPoint(event);
                const deltaX = point.x - dragStartX;
                const deltaY = point.y - dragStartY;
                const distance = Math.hypot(deltaX, deltaY);

                if (!hasMovedPastThreshold && distance >= DRAG_THRESHOLD) {
                    hasMovedPastThreshold = true;
                    isDragging = true;
                    widget.classList.add('is-dragging');
                    trigger.setAttribute('aria-grabbed', 'true');
                }

                if (hasMovedPastThreshold) {
                    if (event.cancelable) {
                        event.preventDefault();
                    }
                    const rawLeft = initialWidgetLeft + deltaX;
                    const rawTop = initialWidgetTop + deltaY;
                    const clamped = clampAssistantPosition(rawLeft, rawTop);
                    currentClampedLeft = clamped.left;
                    currentClampedTop = clamped.top;

                    if (!dragRaf) {
                        dragRaf = window.requestAnimationFrame(function() {
                            dragRaf = 0;
                            if (isDragging) {
                                widget.style.setProperty('left', currentClampedLeft + 'px', 'important');
                                widget.style.setProperty('top', currentClampedTop + 'px', 'important');
                                widget.style.setProperty('right', 'auto', 'important');
                                widget.style.setProperty('bottom', 'auto', 'important');
                            }
                        });
                    }
                }
            }

            function cleanupDragListeners() {
                if (window.PointerEvent) {
                    window.removeEventListener('pointermove', onTriggerPointerMove);
                    window.removeEventListener('pointerup', onTriggerPointerUp);
                    window.removeEventListener('pointercancel', onTriggerPointerCancel);
                } else {
                    document.removeEventListener('mousemove', onTriggerPointerMove);
                    document.removeEventListener('mouseup', onTriggerPointerUp);
                    document.removeEventListener('touchmove', onTriggerPointerMove);
                    document.removeEventListener('touchend', onTriggerPointerUp);
                    document.removeEventListener('touchcancel', onTriggerPointerCancel);
                }
            }

            function onTriggerPointerUp(event) {
                if (activePointerId !== null && event.pointerId !== undefined && event.pointerId !== activePointerId) {
                    return;
                }
                cleanupDragListeners();

                if (dragRaf) {
                    window.cancelAnimationFrame(dragRaf);
                    dragRaf = 0;
                }

                if (trigger.releasePointerCapture && activePointerId !== null) {
                    try { trigger.releasePointerCapture(activePointerId); } catch (e) {}
                }

                if (hasMovedPastThreshold) {
                    placeAssistant(currentClampedLeft, currentClampedTop, true);
                    justDragged = true;
                    window.setTimeout(function() {
                        justDragged = false;
                    }, 180);
                }

                widget.classList.remove('is-dragging');
                trigger.setAttribute('aria-grabbed', 'false');
                isDragging = false;
                hasMovedPastThreshold = false;
                activePointerId = null;
            }

            function onTriggerPointerCancel() {
                cleanupDragListeners();
                if (dragRaf) {
                    window.cancelAnimationFrame(dragRaf);
                    dragRaf = 0;
                }
                if (trigger.releasePointerCapture && activePointerId !== null) {
                    try { trigger.releasePointerCapture(activePointerId); } catch (e) {}
                }
                widget.classList.remove('is-dragging');
                trigger.setAttribute('aria-grabbed', 'false');
                isDragging = false;
                hasMovedPastThreshold = false;
                activePointerId = null;
            }

            if (window.PointerEvent) {
                trigger.addEventListener('pointerdown', onTriggerPointerDown);
            } else {
                trigger.addEventListener('mousedown', onTriggerPointerDown);
                trigger.addEventListener('touchstart', onTriggerPointerDown, { passive: true });
            }

            // ── Single Authoritative Click Handler (Toggle chat open/close) ──
            trigger.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();

                if (justDragged || hasMovedPastThreshold) {
                    return;
                }

                const isCurrentlyOpen = widget.classList.contains('is-open');
                setAssistantOpen(!isCurrentlyOpen);
                if (!isCurrentlyOpen) {
                    checkAssistantHealth(false);
                }
            });

            // ── Desktop Panel Position Clamping & Header Dragging ──────────
            function clampDesktopPanelPosition(left, top) {
                const margin = 16;
                const rect = panel.getBoundingClientRect();
                const width = rect.width || 400;
                const height = rect.height || 580;
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

            function positionDesktopPanel() {
                if (!isDesktopView.matches) return;
                try {
                    const saved = JSON.parse(sessionStorage.getItem(desktopPanelPositionKey) || 'null');
                    if (saved && Number.isFinite(saved.left) && Number.isFinite(saved.top)) {
                        const clamped = clampDesktopPanelPosition(saved.left, saved.top);
                        panel.style.setProperty('left', clamped.left + 'px', 'important');
                        panel.style.setProperty('top', clamped.top + 'px', 'important');
                        panel.style.setProperty('right', 'auto', 'important');
                        panel.style.setProperty('bottom', 'auto', 'important');
                        return;
                    }
                } catch (e) {}

                const widgetRect = widget.getBoundingClientRect();
                const panelWidth = Math.min(400, window.innerWidth - 32);
                const panelHeight = Math.min(560, window.innerHeight - 110);

                if (widget.style.left && widget.style.top) {
                    let targetLeft, targetTop;
                    if (widgetRect.left + 29 > window.innerWidth / 2) {
                        targetLeft = widgetRect.right - panelWidth;
                    } else {
                        targetLeft = widgetRect.left;
                    }
                    if (widgetRect.top + 29 > window.innerHeight / 2) {
                        targetTop = widgetRect.top - panelHeight - 12;
                    } else {
                        targetTop = widgetRect.bottom + 12;
                    }
                    const clamped = clampDesktopPanelPosition(targetLeft, targetTop);
                    panel.style.setProperty('left', clamped.left + 'px', 'important');
                    panel.style.setProperty('top', clamped.top + 'px', 'important');
                    panel.style.setProperty('right', 'auto', 'important');
                    panel.style.setProperty('bottom', 'auto', 'important');
                } else {
                    panel.style.removeProperty('left');
                    panel.style.removeProperty('top');
                    panel.style.setProperty('right', '24px', 'important');
                    panel.style.setProperty('bottom', '92px', 'important');
                }
            }

            let desktopPanelDragState = null;
            let desktopPanelDragFrame = 0;

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
                const deltaX = event.clientX - desktopPanelDragState.startX;
                const deltaY = event.clientY - desktopPanelDragState.startY;
                const clamped = clampDesktopPanelPosition(desktopPanelDragState.initialLeft + deltaX, desktopPanelDragState.initialTop + deltaY);

                if (!desktopPanelDragFrame) {
                    desktopPanelDragFrame = window.requestAnimationFrame(function() {
                        desktopPanelDragFrame = 0;
                        if (!desktopPanelDragState) return;
                        panel.style.setProperty('left', clamped.left + 'px', 'important');
                        panel.style.setProperty('top', clamped.top + 'px', 'important');
                        panel.style.setProperty('right', 'auto', 'important');
                        panel.style.setProperty('bottom', 'auto', 'important');
                    });
                }
            }

            function endDesktopPanelDrag(event) {
                if (!desktopPanelDragState) return;
                if (desktopPanelDragFrame) {
                    window.cancelAnimationFrame(desktopPanelDragFrame);
                    desktopPanelDragFrame = 0;
                }
                const deltaX = event.clientX - desktopPanelDragState.startX;
                const deltaY = event.clientY - desktopPanelDragState.startY;
                const clamped = clampDesktopPanelPosition(desktopPanelDragState.initialLeft + deltaX, desktopPanelDragState.initialTop + deltaY);
                panel.style.setProperty('left', clamped.left + 'px', 'important');
                panel.style.setProperty('top', clamped.top + 'px', 'important');
                panel.style.setProperty('right', 'auto', 'important');
                panel.style.setProperty('bottom', 'auto', 'important');
                panel.classList.remove('is-panel-dragging');

                try {
                    sessionStorage.setItem(desktopPanelPositionKey, JSON.stringify({left: clamped.left, top: clamped.top}));
                } catch (e) {}

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

            window.requestAnimationFrame(loadAssistantPosition);
            window.addEventListener('resize', function() {
                if (widget.style.left) {
                    placeAssistant(parseFloat(widget.style.left), parseFloat(widget.style.top), false);
                }
                if (widget.classList.contains('is-open') && isDesktopView.matches) {
                    positionDesktopPanel();
                }
            });
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', function() {
                    if (widget.style.left) {
                        placeAssistant(parseFloat(widget.style.left), parseFloat(widget.style.top), false);
                    }
                });
            }

            // ── Open / Close Assistant Controller ─────────────────────────
            function setAssistantOpen(isOpen) {
                widget.classList.toggle('is-open', isOpen);
                document.body.classList.toggle('ai-chat-open', isOpen && window.matchMedia('(max-width: 599px)').matches);
                trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
                if (isOpen) {
                    if (isDesktopView.matches) {
                        positionDesktopPanel();
                    }
                    if (liveAnswer) {
                        liveAnswer.scrollTop = liveAnswer.scrollHeight;
                    }
                    if (liveInput) {
                        setTimeout(function() { liveInput.focus(); }, 120);
                    }
                }
            }

            function escapeHtml(value) {
                return String(value).replace(/[&<>"']/g, function(char) {
                    return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'})[char];
                });
            }

            function currentTime() {
                return new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
            }

            function setTyping(isTyping) {
                if (!status) return;
                status.innerHTML = isTyping
                    ? '<span class="ai-assistant-status-dot" aria-hidden="true"></span> Thinking...'
                    : '<span class="ai-assistant-status-dot" aria-hidden="true"></span> Online &amp; Ready';
            }

            function setHealthStatus(state) {
                if (!status) return;
                status.classList.toggle('is-offline', state !== 'online');
                if (state === 'online') {
                    status.innerHTML = '<span class="ai-assistant-status-dot" aria-hidden="true"></span> Online &amp; Ready';
                } else if (state === 'model_unavailable') {
                    status.innerHTML = '<span class="ai-assistant-status-dot" style="background:#F59E0B;" aria-hidden="true"></span> Model Offline';
                } else {
                    status.innerHTML = '<span class="ai-assistant-status-dot" style="background:#EF4444;" aria-hidden="true"></span> AI Offline';
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
                const copyButton = type === 'assistant' ? '<button type="button" class="ai-assistant-copy" aria-label="Copy response text" title="Copy response"><i class="fas fa-copy"></i> <span>Copy</span></button>' : '';

                if (type === 'user') {
                    item.innerHTML = '<div class="ai-msg-bubble user-bubble"><p>' + escapeHtml(message) + '</p></div><div class="ai-assistant-message-meta"><span>' + currentTime() + '</span></div>';
                } else {
                    const formatted = formatAssistantMarkdown(message);
                    item.innerHTML = '<div class="ai-msg-header"><span class="ai-avatar-badge" aria-hidden="true"><i class="fas fa-church"></i></span><strong>' + escapeHtml(title) + '</strong></div><div class="ai-msg-bubble assistant-bubble">' + formatted + '</div>' + stepList + '<div class="ai-assistant-message-meta"><span>' + currentTime() + '</span>' + copyButton + '</div>';
                }

                liveAnswer.appendChild(item);
                liveAnswer.scrollTop = liveAnswer.scrollHeight;
                if (liveInput) {
                    liveInput.focus();
                }
                return item;
            }

            function appendTypingBubble() {
                if (!liveAnswer) {
                    return null;
                }
                liveAnswer.hidden = false;
                removeEmptyState();
                const item = document.createElement('div');
                item.className = 'ai-assistant-chat-message assistant loading';
                item.innerHTML = '<div class="ai-msg-header"><span class="ai-avatar-badge" aria-hidden="true"><i class="fas fa-church"></i></span><strong>' + escapeHtml(chatLabels.title) + '</strong></div><div class="ai-msg-bubble assistant-bubble ai-typing-bubble"><div class="ai-typing-dots" aria-label="' + escapeHtml(chatLabels.typing) + '"><span></span><span></span><span></span></div></div>';
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

            // ── Header Buttons & Controls ─────────────────────────────────
            close.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                setAssistantOpen(false);
            });

            if (mobileBack) {
                mobileBack.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    setAssistantOpen(false);
                });
            }

            if (clear) {
                clear.addEventListener('click', function() {
                    const g = getParishGreeting();
                    liveAnswer.hidden = false;
                    liveAnswer.innerHTML = '<div class="ai-assistant-empty-state" id="aiAssistantEmptyState">' +
                        '<div class="ai-assistant-welcome-icon" aria-hidden="true"><i class="fas fa-cross"></i></div>' +
                        '<div class="ai-assistant-greeting-bubble">' +
                            '<strong id="aiAssistantWelcomeHeading">' + escapeHtml(g.heading) + '</strong>' +
                            '<span id="aiAssistantWelcomeSub">' + escapeHtml(g.sub) + '</span>' +
                        '</div>' +
                        '<div class="ai-assistant-quick-prompts" id="aiAssistantQuickPrompts">' +
                            '<button type="button" class="ai-quick-chip" data-ai-prompt="What are the mass schedules?">⛪ Mass Schedules</button>' +
                            '<button type="button" class="ai-quick-chip" data-ai-prompt="How do I request a Baptismal Certificate?">📜 Baptism Certificate</button>' +
                            '<button type="button" class="ai-quick-chip" data-ai-prompt="What are the requirements for Matrimony/Wedding?">💍 Wedding Guidelines</button>' +
                            '<button type="button" class="ai-quick-chip" data-ai-prompt="How do I track my submitted request?">🔍 Track My Request</button>' +
                        '</div>' +
                    '</div>';
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

            function getParishGreeting() {
                const hour = new Date().getHours();
                if (hour >= 5 && hour < 12) {
                    return {
                        heading: "Good morning, and peace be with you!",
                        sub: "I am your TUGON Parish Guide. How may I assist you with sacramental guidelines, Mass schedules, or certificate requests today?"
                    };
                } else if (hour >= 12 && hour < 18) {
                    return {
                        heading: "Good afternoon! May your day be blessed.",
                        sub: "I am your TUGON Parish Guide. How may I help you with parish records, Mass schedules, or online requests?"
                    };
                } else {
                    return {
                        heading: "Good evening! Peace be with you.",
                        sub: "I am your TUGON Parish Guide. Feel free to ask about certificate requirements, Mass schedules, or request tracking."
                    };
                }
            }

            function updateWelcomeGreeting() {
                const greeting = getParishGreeting();
                const headingEl = document.getElementById('aiAssistantWelcomeHeading');
                const subEl = document.getElementById('aiAssistantWelcomeSub');
                if (headingEl) headingEl.textContent = greeting.heading;
                if (subEl) subEl.textContent = greeting.sub;
            }

            updateWelcomeGreeting();

            // ── Live Form & Input Handlers ────────────────────────────────
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

            // ── Delegated Document Click Handlers ─────────────────────────
            document.addEventListener('click', function(event) {
                const aiOpener = event.target.closest('[data-open-ai-chat], #sidebarAiAssistantLink, #adminSidebarAiAssistantLink, .nav-item-ai');
                if (aiOpener) {
                    if (widget && !window.location.pathname.endsWith('ai-assistant.php')) {
                        event.preventDefault();
                        event.stopPropagation();
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
                    const textBubble = bubble ? (bubble.querySelector('.ai-msg-bubble') || bubble) : null;
                    if (textBubble) {
                        navigator.clipboard.writeText((textBubble.innerText || '').trim()).then(function() {
                            copyButton.classList.add('is-copied');
                            copyButton.innerHTML = '<i class="fas fa-check"></i> <span>Copied</span>';
                            setTimeout(function() {
                                copyButton.classList.remove('is-copied');
                                copyButton.innerHTML = '<i class="fas fa-copy"></i> <span>Copy</span>';
                            }, 1400);
                        });
                    }
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
