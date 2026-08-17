<?php
/**
 * Integration Health Center - Offline/online readiness dashboard for revision compliance.
 */
include_once '../includes/session.php';
include_once '../database/config.php';
include_once '../includes/helpers.php';

requireAdmin();
requirePermission('system.settings');

$page_title = 'Integration Health Center';
$items = getSystemIntegrationReadiness($conn);
$overall = count($items) ? round(array_sum(array_column($items, 'percent')) / count($items)) : 0;
$online_ready = array_filter($items, function ($item) {
    return $item['percent'] >= 88;
});

$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Integration Health' => null
];
?>
<?php include '../templates/header.php'; ?>

<style>
    .integration-page {
        max-width: 1500px;
        margin: 0 auto;
    }

    .integration-hero,
    .integration-card,
    .readiness-panel {
        background: #fff;
        border: 1px solid #e4e7ec;
        border-radius: 8px;
        box-shadow: 0 12px 28px rgba(16, 24, 40, .06);
    }

    .integration-hero {
        border-top: 4px solid #175cd3;
        padding: 22px;
        margin-bottom: 18px;
    }

    .integration-hero h1 {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 8px;
        color: #172033;
        font-size: 1.65rem;
        font-weight: 850;
    }

    .integration-hero p {
        margin: 0;
        max-width: 860px;
        color: #667085;
        line-height: 1.6;
    }

    .readiness-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }

    .readiness-panel {
        padding: 16px;
    }

    .readiness-panel span {
        color: #667085;
        font-size: .88rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .readiness-panel strong {
        display: block;
        margin-top: 6px;
        color: #172033;
        font-size: 1.65rem;
    }

    .integration-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .integration-card {
        padding: 18px;
    }

    .card-top {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
        margin-bottom: 12px;
    }

    .card-top h2 {
        margin: 0;
        color: #172033;
        font-size: 1.05rem;
        font-weight: 850;
    }

    .mode-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        border-radius: 999px;
        padding: 6px 10px;
        background: #eff8ff;
        color: #175cd3;
        font-size: .78rem;
        font-weight: 800;
        white-space: nowrap;
    }

    .percent-wrap {
        display: grid;
        grid-template-columns: 62px 1fr;
        align-items: center;
        gap: 10px;
        margin-bottom: 12px;
    }

    .percent-value {
        color: #101828;
        font-size: 1.4rem;
        font-weight: 900;
    }

    .progress-track {
        height: 10px;
        overflow: hidden;
        border-radius: 999px;
        background: #eef2f6;
    }

    .progress-track span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #175cd3, #12b76a);
    }

    .detail-list {
        margin: 10px 0 0;
        padding-left: 18px;
        color: #475467;
        line-height: 1.55;
    }

    .detail-list li {
        margin-bottom: 4px;
    }

    .needs-title {
        margin: 14px 0 6px;
        color: #854a0e;
        font-size: .88rem;
        font-weight: 850;
        text-transform: uppercase;
    }

    .offline-note {
        margin-top: 18px;
        padding: 14px 16px;
        border: 1px solid #fedf89;
        border-radius: 8px;
        background: #fffbeb;
        color: #7a4b00;
    }

    @media (max-width: 1100px) {
        .integration-list,
        .readiness-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="container-fluid mt-4 integration-page">
    <div class="integration-hero">
        <h1><i class="fas fa-plug-circle-check"></i> Integration Health Center</h1>
        <p>This dashboard shows the localhost/offline readiness and online deployment needs for the seven revision areas. It gives you evidence for the panel today and a clean checklist for next week or next month when the system goes online.</p>
    </div>

    <div class="readiness-grid">
        <div class="readiness-panel">
            <span>Overall Completion</span>
            <strong><?php echo intval($overall); ?>%</strong>
        </div>
        <div class="readiness-panel">
            <span>Revision Areas Checked</span>
            <strong><?php echo count($items); ?>/7</strong>
        </div>
        <div class="readiness-panel">
            <span>Online-Ready Areas</span>
            <strong><?php echo count($online_ready); ?>/7</strong>
        </div>
    </div>

    <div class="integration-list">
        <?php foreach ($items as $item): ?>
            <section class="integration-card">
                <div class="card-top">
                    <div>
                        <h2><?php echo intval($item['number']); ?>. <?php echo e($item['title']); ?></h2>
                        <div class="text-muted small mt-1"><?php echo e($item['status']); ?> | <?php echo e($item['mode']); ?></div>
                    </div>
                    <span class="mode-pill"><i class="fas fa-signal"></i><?php echo e($item['percent'] >= 88 ? 'Strong' : 'Improve'); ?></span>
                </div>

                <div class="percent-wrap">
                    <div class="percent-value"><?php echo intval($item['percent']); ?>%</div>
                    <div class="progress-track"><span style="width: <?php echo intval($item['percent']); ?>%"></span></div>
                </div>

                <ul class="detail-list">
                    <?php foreach ($item['evidence'] as $evidence): ?>
                        <li><?php echo e($evidence); ?></li>
                    <?php endforeach; ?>
                </ul>

                <div class="needs-title">Before Online Deployment</div>
                <ul class="detail-list">
                    <?php foreach ($item['needs'] as $need): ?>
                        <li><?php echo e($need); ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="offline-note">
        <strong>Localhost mode:</strong> The system is allowed to run with offline fallbacks for AI assistance, SMS logging, PHP mail, browser print/PDF, and manual ID review. Before public deployment, configure real SMTP/SMS credentials, install PDF tools on the server, and test backup recovery.
    </div>
</div>

<?php include '../templates/footer.php'; ?>
