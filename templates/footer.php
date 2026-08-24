<?php
/**
 * Footer Template - Closes shared layouts and loads common scripts for user and admin pages.
 */
?>
    <?php if (isset($is_user_area) && $is_user_area): ?>
            </main>
            <footer class="user-footer">
                <span>&copy; 2026 San Lorenzo Ruiz Mission Station</span>
                <span><?php echo e(t('footer.tagline', 'Serving with faith and care')); ?></span>
            </footer>
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
        body.user-area .profile-avatar,
        body.user-area .ai-assistant-trigger {
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
            background: #FFFFFF !important;
            border-top: 1px solid var(--tugon-border) !important;
            color: var(--tugon-muted) !important;
            font-size: 14px !important;
            line-height: 1.5 !important;
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

    <?php if (isLoggedIn() && isUser()): ?>
    <div class="ai-assistant-widget" id="aiAssistantWidget">
        <button class="ai-assistant-trigger" type="button" id="aiAssistantTrigger" aria-label="<?php echo e(t('chatbot.trigger_label', 'AI Parish Assistant')); ?>" aria-expanded="false" aria-grabbed="false" title="Tap to open. Drag to move. Long press to reset position.">
            <span class="ai-assistant-glow" aria-hidden="true"></span>
            <span class="ai-assistant-icon" aria-hidden="true">
                <i class="fas fa-church"></i>
                <i class="fas fa-wand-magic-sparkles"></i>
            </span>
        </button>
        <section class="ai-assistant-panel" id="aiAssistantPanel" aria-hidden="true">
            <div class="ai-assistant-panel-header">
                <button class="ai-assistant-mobile-back" type="button" id="aiAssistantMobileBack" aria-label="Back to previous screen">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </button>
                <div class="ai-assistant-panel-mark" aria-hidden="true">
                    <i class="fas fa-church"></i>
                </div>
                <div class="ai-assistant-panel-identity">
                    <strong>TUGON AI</strong>
                    <span>Parish Assistant</span>
                </div>
                <div class="ai-assistant-status" id="aiAssistantStatus"><span></span> Checking...</div>
                <button class="ai-assistant-tool" type="button" id="aiAssistantClear" aria-label="Clear conversation" title="Clear conversation">
                    <i class="fas fa-trash-can"></i>
                </button>
                <button class="ai-assistant-tool" type="button" id="aiAssistantMinimize" aria-label="Minimize chat" title="Minimize chat">
                    <i class="fas fa-minus"></i>
                </button>
                <button class="ai-assistant-close" type="button" id="aiAssistantClose" aria-label="<?php echo e(t('chatbot.close_label', 'Close AI assistant')); ?>">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ai-assistant-panel-body">
                <div class="ai-assistant-live-answer" id="aiAssistantLiveAnswer">
                    <div class="ai-assistant-empty-state" id="aiAssistantEmptyState">
                        <i class="fas fa-church"></i>
                        <div class="ai-assistant-greeting-bubble">
                            <strong>Hello! I'm TUGON AI, your parish information assistant.</strong>
                            <span>How can I help you today?</span>
                        </div>
                        <div class="ai-assistant-quick" aria-label="Suggested questions">
                            <button type="button" data-ai-prompt="What are the baptism requirements?">Baptism</button>
                            <button type="button" data-ai-prompt="How can I request a parish certificate?">Certificate Request</button>
                            <button type="button" data-ai-prompt="What is the Sunday mass schedule?">Mass schedule</button>
                            <button type="button" data-ai-prompt="How can I make a reservation?">Reservations</button>
                            <button type="button" data-ai-prompt="What are the parish office hours?">Office Hours</button>
                        </div>
                    </div>
                </div>
                <form class="ai-assistant-live-form" id="aiAssistantLiveForm">
                    <label class="ai-assistant-search" for="aiAssistantLiveInput">
                        <i class="fas fa-message" aria-hidden="true"></i>
                        <textarea id="aiAssistantLiveInput" rows="1" maxlength="2000" data-no-autocomplete="true" placeholder="<?php echo e(t('chatbot.placeholder', 'Type your message here...')); ?>"></textarea>
                    </label>
                    <button type="submit" aria-label="<?php echo e(t('chatbot.send', 'Send')); ?>"><i class="fas fa-paper-plane" aria-hidden="true"></i><span class="ai-assistant-send-label"><?php echo e(t('chatbot.send', 'Send')); ?></span></button>
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
    <?php $main_script_version = file_exists(__DIR__ . '/../assets/js/main.js') ? filemtime(__DIR__ . '/../assets/js/main.js') : time(); ?>
    <script src="../assets/js/main.js?v=<?php echo $main_script_version; ?>"></script>
    <?php if (isLoggedIn() && isUser()): ?>
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
            const assistantPositionKey = 'tugonAiFabPosition:v1:<?php echo intval($_SESSION['user_id'] ?? 0); ?>';
            const assistantPhoneView = window.matchMedia('(max-width: 599px)');
            const assistantDragThreshold = 8;
            const assistantEdgeMargin = 10;
            let assistantDragState = null;
            let assistantDragFrame = 0;
            let suppressNextAssistantClick = false;
            let assistantLongPressTimer = 0;
            if (!widget || !trigger || !panel || !close) {
                return;
            }

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
                if (!assistantPhoneView.matches || !widget.style.left) return;
                placeAssistant(parseFloat(widget.style.left), parseFloat(widget.style.top), true);
            });
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', function() {
                    if (!widget.style.left) return;
                    placeAssistant(parseFloat(widget.style.left), parseFloat(widget.style.top), false);
                });
            }

            // Set Assistant Open Function - Documents this helper's role in the parish management workflow.
            function setAssistantOpen(isOpen) {
                widget.classList.toggle('is-open', isOpen);
                document.body.classList.toggle('ai-chat-open', isOpen && window.matchMedia('(max-width: 599px)').matches);
                trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
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
                return Math.min(1800, Math.max(950, String(message || '').length * 9));
            }

            function typeAssistantText(target, text, done) {
                const value = String(text || '');
                let index = 0;
                const speed = Math.max(12, Math.min(26, Math.floor(1200 / Math.max(value.length, 1))));
                target.textContent = '';
                function tick() {
                    target.textContent = value.slice(0, index);
                    liveAnswer.scrollTop = liveAnswer.scrollHeight;
                    index += 1;
                    if (index <= value.length) {
                        window.setTimeout(tick, speed);
                    } else if (typeof done === 'function') {
                        done();
                    }
                }
                tick();
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
                    ? '<ol class="ai-assistant-numbered-list" hidden>' + steps.map(function(step) { return '<li>' + escapeHtml(step) + '</li>'; }).join('') + '</ol>'
                    : '';
                const suggestions = '';
                const copyButton = type === 'assistant' ? '<button type="button" class="ai-assistant-copy">Copy</button>' : '';
                item.innerHTML = '<strong>' + escapeHtml(title) + '</strong><p><span class="ai-assistant-stream-text">' + (stream ? '' : escapeHtml(message)) + '</span></p>' + stepList + suggestions + '<div class="ai-assistant-message-meta"><span>' + currentTime() + '</span>' + copyButton + '</div>';
                liveAnswer.appendChild(item);
                liveAnswer.scrollTop = liveAnswer.scrollHeight;
                if (stream && type === 'assistant') {
                    typeAssistantText(item.querySelector('.ai-assistant-stream-text'), message, function() {
                        const numberedList = item.querySelector('.ai-assistant-numbered-list');
                        if (numberedList) {
                            numberedList.hidden = false;
                        }
                        const suggestionBlock = item.querySelector('.ai-assistant-stream-suggestions');
                        if (suggestionBlock) {
                            suggestionBlock.hidden = false;
                        }
                        if (liveInput) {
                            liveInput.focus();
                        }
                    });
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
                item.innerHTML = '<strong>' + escapeHtml(chatLabels.title) + '</strong><div class="ai-assistant-typing-line"><span>Thinking</span><div class="ai-typing-dots" aria-label="' + escapeHtml(chatLabels.typing) + '"><span></span><span></span><span></span></div></div>';
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

            close.addEventListener('click', function() {
                setAssistantOpen(false);
            });

            if (mobileBack) {
                mobileBack.addEventListener('click', function() {
                    setAssistantOpen(false);
                });
            }

            if (clear && liveAnswer) {
                clear.addEventListener('click', function() {
                    liveAnswer.hidden = false;
                    liveAnswer.innerHTML = '<div class="ai-assistant-empty-state" id="aiAssistantEmptyState"><i class="fas fa-church"></i><div class="ai-assistant-greeting-bubble"><strong>Conversation cleared</strong><span>What can I help you with?</span></div><div class="ai-assistant-quick" aria-label="Suggested questions"><button type="button" data-ai-prompt="What are the certificate requirements?">Certificate requirements</button><button type="button" data-ai-prompt="How can I check my request status?">Request status</button><button type="button" data-ai-prompt="What is the Mass schedule?">Mass schedule</button><button type="button" data-ai-prompt="Show me the latest parish announcements.">Announcements</button></div></div>';
                    conversationHistory.length = 0;
                    if (liveInput) {
                        liveInput.focus();
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
                liveForm.addEventListener('submit', function(event) {
                    event.preventDefault();
                    const message = liveInput.value.trim();
                    if (!message) {
                        return;
                    }
                    liveInput.value = '';
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
                const quickPrompt = event.target.closest('[data-ai-prompt]');
                if (quickPrompt && widget.contains(quickPrompt)) {
                    const prompt = quickPrompt.getAttribute('data-ai-prompt');
                    if (prompt) {
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
