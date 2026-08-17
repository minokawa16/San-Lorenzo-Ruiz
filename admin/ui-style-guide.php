<?php
/**
 * Parish UI Style Guide - previews shared design-system tokens and components.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();

$page_title = 'Parish UI Style Guide';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'UI Style Guide' => null
];
?>
<?php include '../templates/header.php'; ?>

<div class="container-fluid mt-4">
    <?php include '../includes/breadcrumb.php'; ?>
    <?php include '../includes/back_button.php'; ?>

    <div class="pds-page-header">
        <div>
            <h1><i class="fas fa-palette"></i> Parish UI Style Guide</h1>
            <p>Illuminated manuscript tokens and reusable component classes for the parish management system.</p>
        </div>
        <a class="pds-btn pds-btn-primary-gold" href="dashboard.php">
            <i class="fas fa-gauge"></i> Dashboard
        </a>
    </div>
    <div class="pds-tracery-rule" aria-hidden="true"></div>

    <div class="row g-4">
        <div class="col-lg-6">
            <section class="pds-card h-100">
                <div class="pds-card-header"><i class="fas fa-droplet"></i> Color Tokens</div>
                <div class="pds-card-body">
                    <div class="row g-3">
                        <?php
                        $colors = [
                            'Gold Rich' => '--gold-rich',
                            'Gold Soft' => '--gold-soft',
                            'Ivory Background' => '--ivory-bg',
                            'Surface Parchment' => '--surface-parchment',
                            'Surface White' => '--surface-white',
                            'Ink Black' => '--ink-black',
                            'Muted Text' => '--muted-text',
                            'Success Green' => '--success-green',
                            'Success Background' => '--success-bg',
                            'Danger Crimson' => '--danger-crimson',
                            'Danger Background' => '--danger-bg',
                            'Warning Amber' => '--warning-amber',
                            'Warning Background' => '--warning-bg'
                        ];
                        foreach ($colors as $label => $token):
                        ?>
                            <div class="col-md-6">
                                <div class="d-flex align-items-center gap-3">
                                    <span style="width:42px;height:42px;border-radius:8px;border:1px solid var(--warm-border);background:var(<?php echo e($token); ?>);"></span>
                                    <div>
                                        <strong><?php echo e($label); ?></strong>
                                        <div class="text-muted small"><?php echo e($token); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="pds-card h-100">
                <div class="pds-card-header"><i class="fas fa-hand-pointer"></i> Buttons and Badges</div>
                <div class="pds-card-body">
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <button class="pds-btn pds-btn-primary-gold" type="button"><i class="far fa-square-plus"></i> Primary Gold</button>
                        <button class="pds-btn pds-btn-ghost-outline" type="button"><i class="far fa-clone"></i> Ghost Outline</button>
                        <button class="pds-btn pds-btn-success" type="button"><i class="fas fa-check"></i> Success</button>
                        <button class="pds-btn pds-btn-danger" type="button"><i class="fas fa-triangle-exclamation"></i> Danger</button>
                        <button class="pds-btn pds-btn-ghost" type="button"><i class="fas fa-ellipsis"></i> Ghost</button>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="pds-badge pds-badge-pending"><i class="far fa-clock"></i> Pending</span>
                        <span class="pds-badge pds-badge-approved"><i class="far fa-circle-check"></i> Approved</span>
                        <span class="pds-badge pds-badge-rejected"><i class="far fa-circle-xmark"></i> Rejected</span>
                        <span class="pds-badge pds-badge-neutral"><i class="far fa-circle"></i> Neutral</span>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12">
            <section class="pds-card">
                <div class="pds-card-header"><i class="far fa-window-restore"></i> Bento and Featured Card</div>
                <div class="pds-card-body">
                    <div class="pds-bento-grid">
                        <article class="pds-card pds-featured-card pds-bento-feature">
                            <div class="pds-card-header"><i class="far fa-star"></i> Featured Stat</div>
                            <div class="pds-card-body">
                                <h3 class="mb-2">One important item can be emphasized</h3>
                                <p class="pds-muted mb-0">Use this sparingly on dashboards or stat grids only.</p>
                            </div>
                        </article>
                        <article class="pds-card">
                            <div class="pds-card-header">Standard Card</div>
                            <div class="pds-card-body">White surface, gold top rule, ink border.</div>
                        </article>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="pds-card h-100">
                <div class="pds-card-header"><i class="fas fa-pen-to-square"></i> Forms and Alerts</div>
                <div class="pds-card-body">
                    <div class="pds-alert pds-alert-success mb-3">
                        <i class="fas fa-circle-check"></i>
                        <div><strong>Success alert</strong><br><span class="text-muted">Use for completed actions and saved changes.</span></div>
                    </div>
                    <label class="pds-form-label" for="styleGuideInput">Sample Field</label>
                    <input class="form-control pds-form-control mb-3" id="styleGuideInput" type="text" value="San Lorenzo Ruiz Mission Station">
                    <label class="pds-form-label" for="styleGuideSelect">Sample Select</label>
                    <select class="form-select pds-form-select" id="styleGuideSelect">
                        <option>Certificate Request</option>
                        <option>Sacramental Record</option>
                        <option>Announcement</option>
                    </select>
                    <div class="pds-field mt-3">
                        <label class="pds-form-label" for="styleGuideError">Field With Error</label>
                        <input class="form-control pds-form-control" id="styleGuideError" type="text" value="">
                        <div class="pds-field-error">This field needs attention.</div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="pds-card h-100">
                <div class="pds-card-header"><i class="fas fa-table"></i> Table, Empty State, Skeleton</div>
                <div class="pds-card-body">
                    <div class="pds-table-wrap mb-3">
                        <table class="pds-table">
                            <thead><tr><th>Module</th><th>Status</th><th>Count</th></tr></thead>
                            <tbody>
                                <tr><td>Requests</td><td><span class="pds-badge pds-badge-pending">Pending</span></td><td>12</td></tr>
                                <tr><td>Records</td><td><span class="pds-badge pds-badge-approved">Active</span></td><td>48</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pds-empty-state p-4">
                        <i class="fas fa-inbox"></i>
                        <strong>No records yet</strong>
                        <div>Use this state when a table or list has no results.</div>
                    </div>
                    <div class="pds-skeleton mt-3" style="width: 70%;"></div>
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="pds-card h-100">
                <div class="pds-card-header"><i class="far fa-clock"></i> Timeline</div>
                <div class="pds-card-body">
                    <?php echo pdsTimeline([
                        ['title' => 'Request submitted', 'meta' => 'Today, 9:00 AM', 'body' => 'The parishioner sent a certificate request.'],
                        ['title' => 'Admin review', 'meta' => 'Today, 10:15 AM', 'body' => 'The office checked the attached details.'],
                        ['title' => 'Ready for release', 'meta' => 'Pending', 'body' => 'Final status appears here when completed.']
                    ]); ?>
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="pds-card h-100">
                <div class="pds-card-header"><i class="fas fa-paragraph"></i> Drop Cap and Modal Shell</div>
                <div class="pds-card-body">
                    <p class="pds-dropcap">Welcome messages can use a single illuminated first letter. This is reserved for hero or introduction copy, not dense tables or repeated card text.</p>
                    <div class="modal-content pds-modal-shell position-static mt-3">
                        <div class="modal-header">
                            <h5 class="modal-title">Modal Title</h5>
                        </div>
                        <div class="modal-body">Use this shell class on Bootstrap modal content.</div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
