<?php
/**
 * Parish Leadership & Organizational Hierarchy View
 * Public / Parishioner interactive 5-tier visual org tree.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/OrganizationService.php';

requireLogin();
if (!isUser()) {
    redirect('../auth/login.php');
}

$page_title = 'Parish Leadership';
$breadcrumbs = [
    'Dashboard' => 'index.php',
    'Parish Leadership' => null
];

$orgService = new OrganizationService($conn);
$tree = $orgService->getHierarchyTree();

// Calculate metrics
$totalRoles = 0;
$assignedCount = 0;
$vacantCount = 0;
$ministryCount = count($tree['tier5']);

foreach ($tree['all_tiers'] as $tier) {
    foreach ($tier['positions'] as $pos) {
        $totalRoles++;
        if ($pos['is_vacant']) {
            $vacantCount++;
        } else {
            $assignedCount += count($pos['occupants']);
        }
    }
}

include __DIR__ . '/../templates/header.php';
include __DIR__ . '/../includes/breadcrumb.php';
include __DIR__ . '/../includes/back_button.php';
?>

<style>
/* --- Organizational Hierarchy Visual Theme --- */
.org-container {
    max-width: 1320px;
    margin: 0 auto 3rem auto;
}

.org-hero-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 2.25rem 2.5rem;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.05);
    margin-bottom: 2.5rem;
    position: relative;
    overflow: hidden;
}

.org-hero-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #c89b3c 0%, #2e3a2d 50%, #c89b3c 100%);
}

.org-hero-title {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 2rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.5rem;
}

.org-hero-subtitle {
    color: #64748b;
    font-size: 1rem;
    max-width: 750px;
    line-height: 1.6;
    margin-bottom: 1.5rem;
}

.org-metric-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.org-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 9999px;
    padding: 0.4rem 1rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: #334155;
}

.org-pill i {
    color: #c89b3c;
}

.org-pill.pill-vacant i {
    color: #d97706;
}

/* --- Tree Hierarchy Connectors & Grid --- */
.org-tree {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    padding-bottom: 2rem;
}

/* Vertical stem connectors between tiers */
.org-connector-vertical {
    width: 2px;
    height: 38px;
    background: #cbd5e1;
    margin: 0 auto;
    position: relative;
}

.org-connector-vertical::after {
    content: '';
    position: absolute;
    bottom: -3px;
    left: -3px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #94a3b8;
}

/* Horizontal branching connector for Rank 4 and 5 */
.org-branch-wrap {
    width: 100%;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.org-branch-bar {
    width: 80%;
    max-width: 900px;
    height: 2px;
    background: #cbd5e1;
    margin-bottom: 1rem;
    position: relative;
}

.org-branch-bar::before,
.org-branch-bar::after {
    content: '';
    position: absolute;
    top: 0;
    width: 2px;
    height: 14px;
    background: #cbd5e1;
}

.org-branch-bar::before { left: 0; }
.org-branch-bar::after { right: 0; }

/* Section Banner Labels */
.org-tier-label {
    text-align: center;
    margin-bottom: 1.25rem;
}

.org-tier-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: #f1f5f9;
    color: #475569;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    padding: 0.35rem 0.9rem;
    border-radius: 9999px;
    border: 1px solid #e2e8f0;
}

.org-tier-title {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 1.35rem;
    font-weight: 700;
    color: #1e293b;
    margin-top: 0.35rem;
}

/* --- Card Styles --- */
.org-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
    padding: 1.5rem;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
    display: flex;
    flex-direction: column;
}

.org-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.08);
}

/* Rank-Specific Styling */
.org-card-rank1 {
    width: 100%;
    max-width: 460px;
    border-top: 4px solid #c89b3c;
    background: linear-gradient(180deg, #ffffff 0%, #fffdfa 100%);
    box-shadow: 0 6px 24px rgba(200, 155, 60, 0.12);
}

.org-card-rank2 {
    width: 100%;
    max-width: 440px;
    border-top: 4px solid #0d9488;
}

.org-card-rank3 {
    width: 100%;
    max-width: 440px;
    border-top: 4px solid #3b82f6;
}

.org-card-rank4 {
    border-top: 4px solid #4f46e5;
}

.org-card-rank5 {
    border-top: 4px solid #059669;
}

/* Vacant Card State */
.org-card-vacant {
    background: #f8fafc;
    border: 2px dashed #cbd5e1 !important;
    box-shadow: none !important;
}

.org-card-vacant:hover {
    border-color: #94a3b8 !important;
    background: #f1f5f9;
}

/* Card Interior Elements */
.org-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.org-role-badge {
    font-size: 0.725rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.25rem 0.65rem;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.badge-gold { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.badge-teal { background: #ccfbf1; color: #115e59; border: 1px solid #99f6e4; }
.badge-blue { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
.badge-indigo { background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }
.badge-emerald { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.badge-amber { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }

.org-status-badge {
    font-size: 0.7rem;
    font-weight: 600;
    border-radius: 9999px;
    padding: 0.2rem 0.55rem;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}

.status-active { background: #dcfce7; color: #15803d; }
.status-vacant { background: #fef3c7; color: #b45309; }

.org-role-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.35rem;
    line-height: 1.35;
}

.org-profile {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-top: 0.75rem;
    margin-bottom: 1rem;
}

.org-avatar {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    object-fit: cover;
    background: #e2e8f0;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    font-weight: 700;
    color: #475569;
    border: 2px solid #ffffff;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.avatar-gold { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #92400e; }
.avatar-teal { background: linear-gradient(135deg, #ccfbf1, #99f6e4); color: #115e59; }
.avatar-blue { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1e40af; }
.avatar-indigo { background: linear-gradient(135deg, #e0e7ff, #c7d2fe); color: #3730a3; }
.avatar-emerald { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; }
.avatar-vacant { background: #e2e8f0; color: #94a3b8; border: 2px dashed #cbd5e1; }

.org-member-name {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.3;
}

.org-member-title {
    font-size: 0.8rem;
    color: #64748b;
    margin-top: 0.15rem;
}

.org-bio {
    font-size: 0.825rem;
    color: #64748b;
    line-height: 1.5;
    margin-bottom: 1rem;
}

.org-contacts {
    margin-top: auto;
    padding-top: 0.75rem;
    border-top: 1px solid #f1f5f9;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.org-contact-item {
    font-size: 0.8rem;
    color: #475569;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
    transition: color 0.15s;
}

.org-contact-item i {
    width: 14px;
    color: #94a3b8;
    text-align: center;
}

.org-contact-item:hover {
    color: #2e3a2d;
}

/* Grids for Rank 4 and Rank 5 */
.org-grid-rank4 {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.25rem;
    width: 100%;
}

.org-grid-rank5 {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.25rem;
    width: 100%;
}

@media (max-width: 1024px) {
    .org-grid-rank4 {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 640px) {
    .org-grid-rank4 {
        grid-template-columns: 1fr;
    }
    .org-grid-rank5 {
        grid-template-columns: 1fr;
    }
    .org-hero-card {
        padding: 1.5rem;
    }
    .org-hero-title {
        font-size: 1.5rem;
    }
}
</style>

<div class="org-container">

    <!-- Hero Card -->
    <div class="org-hero-card">
        <h1 class="org-hero-title">Parish Leadership & Pastoral Organization</h1>
        <p class="org-hero-subtitle">
            The ecclesiastical hierarchy, clergy, Chancery administration, Parish Pastoral Council Executive Board, 
            and dynamic ministry coordinators serving the San Lorenzo Ruiz Mission Station.
        </p>
        <div class="org-metric-pills">
            <span class="org-pill"><i class="fas fa-sitemap"></i> 5 Canonical Tiers</span>
            <span class="org-pill"><i class="fas fa-user-check"></i> <?php echo $assignedCount; ?> Active Appointees</span>
            <span class="org-pill"><i class="fas fa-hands-holding-circle"></i> <?php echo $ministryCount; ?> Apostolic Ministries</span>
            <?php if ($vacantCount > 0): ?>
            <span class="org-pill pill-vacant"><i class="fas fa-hourglass-half"></i> <?php echo $vacantCount; ?> Position Open / Vacant</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tree View -->
    <div class="org-tree">

        <!-- ================= TIER 1: PARISH PRIEST ================= -->
        <div class="org-tier-label">
            <span class="org-tier-tag"><i class="fas fa-cross"></i> Rank 1 &bull; Canonical Head</span>
        </div>

        <?php 
        $t1 = $tree['tier1']; 
        $t1Occupant = $t1['occupants'][0] ?? null;
        $t1Vacant = empty($t1Occupant);
        ?>
        <div class="org-card org-card-rank1 <?php echo $t1Vacant ? 'org-card-vacant' : ''; ?>">
            <div class="org-card-header">
                <span class="org-role-badge badge-gold"><i class="fas fa-crown"></i> Pastoral & Canonical Head</span>
                <span class="org-status-badge <?php echo $t1Vacant ? 'status-vacant' : 'status-active'; ?>">
                    <i class="fas <?php echo $t1Vacant ? 'fa-hourglass' : 'fa-circle-check'; ?>"></i>
                    <?php echo $t1Vacant ? 'Vacant' : 'Assigned'; ?>
                </span>
            </div>
            <div class="org-role-title"><?php echo e($t1['title'] ?? 'Parish Priest'); ?></div>

            <?php if (!$t1Vacant): ?>
                <div class="org-profile">
                    <?php if (!empty($t1Occupant['photo_url'])): ?>
                        <img src="<?php echo e(BASE_URL . ltrim($t1Occupant['photo_url'], '/')); ?>" alt="<?php echo e($t1Occupant['full_name']); ?>" class="org-avatar">
                    <?php else: ?>
                        <div class="org-avatar avatar-gold">
                            <?php echo strtoupper(substr($t1Occupant['full_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div class="org-member-name"><?php echo e($t1Occupant['display_name']); ?></div>
                        <div class="org-member-title">Pastor & Spiritual Director</div>
                    </div>
                </div>
                <?php if (!empty($t1Occupant['bio'])): ?>
                    <div class="org-bio"><?php echo e($t1Occupant['bio']); ?></div>
                <?php endif; ?>
                <div class="org-contacts">
                    <?php if (!empty($t1Occupant['email'])): ?>
                        <a href="mailto:<?php echo e($t1Occupant['email']); ?>" class="org-contact-item">
                            <i class="fas fa-envelope"></i> <?php echo e($t1Occupant['email']); ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($t1Occupant['phone'])): ?>
                        <a href="tel:<?php echo e($t1Occupant['phone']); ?>" class="org-contact-item">
                            <i class="fas fa-phone"></i> <?php echo e($t1Occupant['phone']); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="org-profile">
                    <div class="org-avatar avatar-vacant"><i class="fas fa-user-clock"></i></div>
                    <div>
                        <div class="org-member-name text-muted">Vacant / Pending Appointment</div>
                        <div class="org-member-title">Awaiting Chancery / Canonical Decree</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Connector Stem 1 -> 2 -->
        <div class="org-connector-vertical"></div>

        <!-- ================= TIER 2: ASSISTANT PRIEST (PAROCHIAL VICAR) ================= -->
        <div class="org-tier-label">
            <span class="org-tier-tag"><i class="fas fa-church"></i> Rank 2 &bull; Parochial Vicar</span>
        </div>

        <div class="org-vicar-wrap" style="display: flex; justify-content: center; gap: 1.25rem; flex-wrap: wrap; width: 100%;">
        <?php 
        $t2Positions = $tree['tier2'];
        foreach ($t2Positions as $t2):
            $t2Occupants = $t2['occupants'] ?? [];
            $t2Vacant = empty($t2Occupants);
        ?>
        <div class="org-card org-card-rank2 <?php echo $t2Vacant ? 'org-card-vacant' : ''; ?>" style="flex: 1 1 320px; max-width: 440px;">
            <div class="org-card-header">
                <span class="org-role-badge badge-teal"><i class="fas fa-cross"></i> Parochial Vicar</span>
                <span class="org-status-badge <?php echo $t2Vacant ? 'status-vacant' : 'status-active'; ?>">
                    <i class="fas <?php echo $t2Vacant ? 'fa-hourglass' : 'fa-circle-check'; ?>"></i>
                    <?php echo $t2Vacant ? 'Vacant' : 'Assigned'; ?>
                </span>
            </div>
            <div class="org-role-title"><?php echo e($t2['title']); ?></div>

            <?php if (!$t2Vacant): ?>
                <?php foreach ($t2Occupants as $occ): ?>
                    <div class="org-profile">
                        <?php if (!empty($occ['photo_url'])): ?>
                            <img src="<?php echo e(BASE_URL . ltrim($occ['photo_url'], '/')); ?>" alt="<?php echo e($occ['full_name']); ?>" class="org-avatar">
                        <?php else: ?>
                            <div class="org-avatar avatar-teal">
                                <?php echo strtoupper(substr($occ['full_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="org-member-name"><?php echo e($occ['display_name']); ?></div>
                            <div class="org-member-title">Assistant Clergy & Sacramental Ministry</div>
                        </div>
                    </div>
                    <?php if (!empty($occ['bio'])): ?>
                        <div class="org-bio"><?php echo e($occ['bio']); ?></div>
                    <?php endif; ?>
                    <div class="org-contacts">
                        <?php if (!empty($occ['email'])): ?>
                            <a href="mailto:<?php echo e($occ['email']); ?>" class="org-contact-item">
                                <i class="fas fa-envelope"></i> <?php echo e($occ['email']); ?>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($occ['phone'])): ?>
                            <a href="tel:<?php echo e($occ['phone']); ?>" class="org-contact-item">
                                <i class="fas fa-phone"></i> <?php echo e($occ['phone']); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="org-profile">
                    <div class="org-avatar avatar-vacant"><i class="fas fa-user-clock"></i></div>
                    <div>
                        <div class="org-member-name text-muted">Vacant / No Current Vicar</div>
                        <div class="org-member-title">Clergy duties covered by Parish Priest</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>

        <!-- Connector Stem 2 -> 3 -->
        <div class="org-connector-vertical"></div>

        <!-- ================= TIER 3: PARISH SECRETARY ================= -->
        <div class="org-tier-label">
            <span class="org-tier-tag"><i class="fas fa-briefcase"></i> Rank 3 &bull; Office & Chancery Operations</span>
        </div>

        <?php 
        $t3 = $tree['tier3']; 
        $t3Occupant = $t3['occupants'][0] ?? null;
        $t3Vacant = empty($t3Occupant);
        ?>
        <div class="org-card org-card-rank3 <?php echo $t3Vacant ? 'org-card-vacant' : ''; ?>">
            <div class="org-card-header">
                <span class="org-role-badge badge-blue"><i class="fas fa-folder-open"></i> Sacramental Operations</span>
                <span class="org-status-badge <?php echo $t3Vacant ? 'status-vacant' : 'status-active'; ?>">
                    <i class="fas <?php echo $t3Vacant ? 'fa-hourglass' : 'fa-circle-check'; ?>"></i>
                    <?php echo $t3Vacant ? 'Vacant' : 'Assigned'; ?>
                </span>
            </div>
            <div class="org-role-title"><?php echo e($t3['title'] ?? 'Parish Secretary'); ?></div>

            <?php if (!$t3Vacant): ?>
                <div class="org-profile">
                    <?php if (!empty($t3Occupant['photo_url'])): ?>
                        <img src="<?php echo e(BASE_URL . ltrim($t3Occupant['photo_url'], '/')); ?>" alt="<?php echo e($t3Occupant['full_name']); ?>" class="org-avatar">
                    <?php else: ?>
                        <div class="org-avatar avatar-blue">
                            <?php echo strtoupper(substr($t3Occupant['full_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div class="org-member-name"><?php echo e($t3Occupant['display_name']); ?></div>
                        <div class="org-member-title">Chancery Administrator</div>
                    </div>
                </div>
                <?php if (!empty($t3Occupant['bio'])): ?>
                    <div class="org-bio"><?php echo e($t3Occupant['bio']); ?></div>
                <?php endif; ?>
                <div class="org-contacts">
                    <?php if (!empty($t3Occupant['email'])): ?>
                        <a href="mailto:<?php echo e($t3Occupant['email']); ?>" class="org-contact-item">
                            <i class="fas fa-envelope"></i> <?php echo e($t3Occupant['email']); ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($t3Occupant['phone'])): ?>
                        <a href="tel:<?php echo e($t3Occupant['phone']); ?>" class="org-contact-item">
                            <i class="fas fa-phone"></i> <?php echo e($t3Occupant['phone']); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="org-profile">
                    <div class="org-avatar avatar-vacant"><i class="fas fa-user-clock"></i></div>
                    <div>
                        <div class="org-member-name text-muted">Vacant / Pending Appointment</div>
                        <div class="org-member-title">Managed temporarily by Pastoral Council</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Connector Stem 3 -> 4 -->
        <div class="org-connector-vertical"></div>

        <!-- ================= TIER 4: PPC EXECUTIVE BOARD ================= -->
        <div class="org-branch-wrap">
            <div class="org-branch-bar"></div>
            <div class="org-tier-label">
                <span class="org-tier-tag"><i class="fas fa-users-gear"></i> Rank 4 &bull; Lay Pastoral Council</span>
                <div class="org-tier-title">Parish Pastoral Council (PPC) Executive Board</div>
            </div>

            <div class="org-grid-rank4">
                <?php 
                foreach ($tree['tier4'] as $p4):
                    $occ = $p4['occupants'][0] ?? null;
                    $p4Vacant = empty($occ);
                ?>
                <div class="org-card org-card-rank4 <?php echo $p4Vacant ? 'org-card-vacant' : ''; ?>">
                    <div class="org-card-header">
                        <span class="org-role-badge badge-indigo"><i class="fas fa-award"></i> Executive Board</span>
                        <span class="org-status-badge <?php echo $p4Vacant ? 'status-vacant' : 'status-active'; ?>">
                            <i class="fas <?php echo $p4Vacant ? 'fa-hourglass' : 'fa-circle-check'; ?>"></i>
                            <?php echo $p4Vacant ? 'Vacant' : 'Assigned'; ?>
                        </span>
                    </div>
                    <div class="org-role-title"><?php echo e($p4['title']); ?></div>

                    <?php if (!$p4Vacant): ?>
                        <div class="org-profile">
                            <?php if (!empty($occ['photo_url'])): ?>
                                <img src="<?php echo e(BASE_URL . ltrim($occ['photo_url'], '/')); ?>" alt="<?php echo e($occ['full_name']); ?>" class="org-avatar">
                            <?php else: ?>
                                <div class="org-avatar avatar-indigo">
                                    <?php echo strtoupper(substr($occ['full_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <div class="org-member-name"><?php echo e($occ['display_name']); ?></div>
                                <div class="org-member-title">Council Officer</div>
                            </div>
                        </div>
                        <div class="org-contacts">
                            <?php if (!empty($occ['email'])): ?>
                                <a href="mailto:<?php echo e($occ['email']); ?>" class="org-contact-item">
                                    <i class="fas fa-envelope"></i> <?php echo e($occ['email']); ?>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($occ['phone'])): ?>
                                <a href="tel:<?php echo e($occ['phone']); ?>" class="org-contact-item">
                                    <i class="fas fa-phone"></i> <?php echo e($occ['phone']); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="org-profile">
                            <div class="org-avatar avatar-vacant"><i class="fas fa-user-clock"></i></div>
                            <div>
                                <div class="org-member-name text-muted">Vacant / Election Pending</div>
                                <div class="org-member-title">Open Council Seat</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Connector Stem 4 -> 5 -->
        <div class="org-connector-vertical" style="height: 48px; margin-top: 1.5rem;"></div>

        <!-- ================= TIER 5: MINISTRY COORDINATORS ================= -->
        <div class="org-branch-wrap">
            <div class="org-branch-bar"></div>
            <div class="org-tier-label">
                <span class="org-tier-tag"><i class="fas fa-people-group"></i> Rank 5 &bull; Dynamic Commissions</span>
                <div class="org-tier-title">Commission & Ministry Coordinators</div>
            </div>

            <div class="org-grid-rank5">
                <?php 
                foreach ($tree['tier5'] as $p5):
                    $occ = $p5['occupants'][0] ?? null;
                    $p5Vacant = empty($occ);
                ?>
                <div class="org-card org-card-rank5 <?php echo $p5Vacant ? 'org-card-vacant' : ''; ?>">
                    <div class="org-card-header">
                        <span class="org-role-badge badge-emerald"><i class="fas fa-hands-praying"></i> Ministry</span>
                        <span class="org-status-badge <?php echo $p5Vacant ? 'status-vacant' : 'status-active'; ?>">
                            <i class="fas <?php echo $p5Vacant ? 'fa-hourglass' : 'fa-circle-check'; ?>"></i>
                            <?php echo $p5Vacant ? 'Vacant' : 'Assigned'; ?>
                        </span>
                    </div>
                    <div class="org-role-title"><?php echo e($p5['title']); ?></div>
                    <?php if (!empty($p5['description'])): ?>
                        <div class="text-muted small mb-2"><?php echo e($p5['description']); ?></div>
                    <?php endif; ?>

                    <?php if (!$p5Vacant): ?>
                        <div class="org-profile">
                            <?php if (!empty($occ['photo_url'])): ?>
                                <img src="<?php echo e(BASE_URL . ltrim($occ['photo_url'], '/')); ?>" alt="<?php echo e($occ['full_name']); ?>" class="org-avatar">
                            <?php else: ?>
                                <div class="org-avatar avatar-emerald">
                                    <?php echo strtoupper(substr($occ['full_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <div class="org-member-name"><?php echo e($occ['display_name']); ?></div>
                                <div class="org-member-title">Ministry Coordinator</div>
                            </div>
                        </div>
                        <div class="org-contacts">
                            <?php if (!empty($occ['email'])): ?>
                                <a href="mailto:<?php echo e($occ['email']); ?>" class="org-contact-item">
                                    <i class="fas fa-envelope"></i> <?php echo e($occ['email']); ?>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($occ['phone'])): ?>
                                <a href="tel:<?php echo e($occ['phone']); ?>" class="org-contact-item">
                                    <i class="fas fa-phone"></i> <?php echo e($occ['phone']); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="org-profile">
                            <div class="org-avatar avatar-vacant"><i class="fas fa-user-plus"></i></div>
                            <div>
                                <div class="org-member-name text-muted">Vacant / Open Role</div>
                                <div class="org-member-title">Pending Coordinator Appointment</div>
                            </div>
                        </div>
                        <div class="small text-muted fst-italic mt-2">
                            Parishioners interested in volunteering may inquire through the parish chancery office.
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
