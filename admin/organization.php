<?php
/**
 * Parish Organizational Hierarchy Management
 * Minimalist, professional executive dashboard with direct fill-in-the-blank inputs,
 * fixed action button alignment, and dynamic Assistant Priest management.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/OrganizationService.php';

requireLogin();
requireAdmin();
requirePermission('users.view');

$page_title = 'Parish Organization';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Parish Organization' => null
];

$orgService = new OrganizationService($conn);
$error = '';
$success = '';

// Handle Direct POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'set_occupant_direct') {
            $posId = (int)($_POST['position_id'] ?? 0);
            $occupantName = trim((string)($_POST['occupant_name'] ?? ''));

            if ($posId <= 0) {
                throw new DomainException('Invalid position.');
            }

            $orgService->setOccupantDirect($posId, $occupantName, (int)$_SESSION['user_id']);
            $success = $occupantName === '' 
                ? 'Position vacated successfully.' 
                : "Assigned '{$occupantName}' successfully.";
        } elseif ($action === 'vacate_position') {
            $posId = (int)($_POST['position_id'] ?? 0);
            if ($posId <= 0) {
                throw new DomainException('Invalid position.');
            }

            $orgService->setOccupantDirect($posId, '', (int)$_SESSION['user_id']);
            $success = 'Position vacated and cleared.';
        } elseif ($action === 'add_assistant_priest') {
            $name = trim((string)($_POST['occupant_name'] ?? ''));
            $newId = $orgService->addAssistantPriest($name, (int)$_SESSION['user_id']);
            $success = 'Assistant Priest card added successfully.';
        } elseif ($action === 'remove_assistant_priest') {
            $posId = (int)($_POST['position_id'] ?? 0);
            $orgService->removeAssistantPriest($posId, (int)$_SESSION['user_id']);
            $success = 'Assistant Priest slot removed.';
        } elseif ($action === 'save_ministry') {
            $posId = (int)($_POST['position_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $occupant = trim((string)($_POST['occupant_name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $displayOrder = (int)($_POST['display_order'] ?? 0);

            if ($title === '') {
                throw new DomainException('Ministry title is required.');
            }

            if ($posId > 0) {
                $orgService->updateMinistryRole($posId, $title, $description, $displayOrder, (int)$_SESSION['user_id']);
                if ($occupant !== '') {
                    $orgService->setOccupantDirect($posId, $occupant, (int)$_SESSION['user_id']);
                }
                $success = 'Ministry updated successfully.';
            } else {
                $newPosId = $orgService->createMinistryRole($title, $description, $displayOrder, (int)$_SESSION['user_id']);
                if ($occupant !== '') {
                    $orgService->setOccupantDirect($newPosId, $occupant, (int)$_SESSION['user_id']);
                }
                $success = 'New ministry coordinator card created.';
            }
        } elseif ($action === 'archive_ministry') {
            $posId = (int)($_POST['position_id'] ?? 0);
            $orgService->archiveMinistryRole($posId, (int)$_SESSION['user_id']);
            $success = 'Ministry role archived.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load fresh hierarchy tree
$tree = $orgService->getHierarchyTree();
$allPositions = $orgService->getPositions(false);

// Calculate metrics
$totalRoles = count($allPositions);
$assignedCount = 0;
$vacantCount = 0;
$dynamicMinistriesCount = 0;

foreach ($allPositions as $p) {
    if ((int)$p['rank_level'] === 5) {
        $dynamicMinistriesCount++;
    }
    if ((int)$p['active_occupants_count'] > 0) {
        $assignedCount += (int)$p['active_occupants_count'];
    } else {
        $vacantCount++;
    }
}

include __DIR__ . '/../templates/header.php';
?>

<style>
/* --- Minimalist Executive Dashboard Theme --- */
.org-executive-wrap {
    max-width: 1320px;
    margin: 0 auto 3rem auto;
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    color: #0f172a;
}

/* Header & Controls */
.exec-header {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.5rem 1.75rem;
    margin-bottom: 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.25rem;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}

.exec-title {
    font-size: 1.375rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.02em;
    margin: 0 0 0.25rem 0;
}

.exec-subtitle {
    font-size: 0.875rem;
    color: #64748b;
    margin: 0;
}

.exec-metrics-bar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.exec-metric-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.75rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #475569;
}

.exec-metric-tag strong {
    color: #0f172a;
}

/* Tiers & Grid Layout */
.exec-tier-section {
    margin-bottom: 2.25rem;
}

.exec-tier-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.85rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #f1f5f9;
}

.exec-tier-title {
    font-size: 0.8125rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #475569;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.exec-tier-title span.rank-badge {
    background: #e2e8f0;
    color: #334155;
    padding: 0.15rem 0.45rem;
    border-radius: 4px;
    font-size: 0.75rem;
}

.exec-btn-add {
    font-size: 0.8125rem;
    font-weight: 600;
    color: #0f172a;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 0.35rem 0.75rem;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    cursor: pointer;
    transition: all 0.15s ease;
}

.exec-btn-add:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}

/* Standardized Executive Cards */
.exec-card-grid {
    display: grid;
    gap: 1rem;
    width: 100%;
}

.grid-single-center {
    display: flex;
    justify-content: center;
}

.grid-single-center .exec-card {
    width: 100%;
    max-width: 520px;
}

.grid-cols-multi {
    grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
}

.exec-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 1.15rem 1.25rem;
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 165px; /* Fixed height for reliable alignment */
    transition: border-color 0.15s, box-shadow 0.15s;
}

.exec-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
}

.exec-card-vacant {
    background: #fafbfc;
    border-style: dashed;
}

/* Card Header */
.exec-card-header {
    margin-bottom: 0.75rem;
    padding-right: 5rem; /* Room for pinned action buttons */
}

.exec-card-role {
    font-size: 0.9375rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.3;
    margin-bottom: 0.25rem;
}

.exec-status-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
}

.status-tag-active {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.status-tag-active::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #16a34a;
}

.status-tag-vacant {
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #e2e8f0;
}

/* Pinned Action Controls (Top-Right of EVERY card) */
.exec-card-pinned-actions {
    position: absolute;
    top: 1rem;
    right: 1rem;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.btn-exec-ghost {
    background: transparent;
    border: none;
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.15s;
}

.btn-exec-ghost:hover {
    background: #f1f5f9;
    color: #0f172a;
}

.btn-exec-vacate {
    color: #94a3b8;
}

.btn-exec-vacate:hover {
    background: #fef2f2;
    color: #dc2626;
}

.btn-exec-remove {
    color: #94a3b8;
}

.btn-exec-remove:hover {
    background: #fef2f2;
    color: #dc2626;
}

/* Card Body & Direct Input */
.exec-card-body {
    flex-grow: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.exec-occupant-name {
    font-size: 1rem;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 0.15rem;
}

.exec-occupant-desc {
    font-size: 0.8125rem;
    color: #64748b;
}

/* Direct Fill-in-the-Blank Input Form */
.exec-direct-input-form {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin-top: 0.35rem;
}

.exec-input-text {
    flex-grow: 1;
    font-size: 0.875rem;
    padding: 0.45rem 0.65rem;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    color: #0f172a;
    background: #ffffff;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}

.exec-input-text:focus {
    border-color: #0f172a;
    box-shadow: 0 0 0 2px rgba(15, 23, 42, 0.08);
}

.exec-btn-save {
    background: #0f172a;
    color: #ffffff;
    border: none;
    font-size: 0.8125rem;
    font-weight: 600;
    padding: 0.45rem 0.8rem;
    border-radius: 6px;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s;
}

.exec-btn-save:hover {
    background: #1e293b;
}

.exec-btn-edit-toggle {
    font-size: 0.775rem;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 0.2rem 0.5rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    margin-top: 0.4rem;
    align-self: flex-start;
}

.exec-btn-edit-toggle:hover {
    background: #f1f5f9;
    color: #0f172a;
}

/* Tree Connectors */
.exec-connector-stem {
    width: 2px;
    height: 24px;
    background: #e2e8f0;
    margin: 0 auto;
}
</style>

<div class="org-executive-wrap">

    <!-- Flash Messages -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm mb-3 py-2 px-3 small" role="alert">
            <i class="fas fa-check-circle me-1.5 text-success"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm mb-3 py-2 px-3 small" role="alert">
            <i class="fas fa-circle-exclamation me-1.5 text-danger"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Minimalist Executive Header -->
    <div class="exec-header">
        <div>
            <h1 class="exec-title">Parish Organizational Hierarchy</h1>
            <p class="exec-subtitle">Executive governance and apostolic leadership directory with direct name editing.</p>
        </div>
        <div class="exec-metrics-bar">
            <div class="exec-metric-tag">
                <span>Ranks:</span> <strong>5 Tiers</strong>
            </div>
            <div class="exec-metric-tag">
                <span>Active:</span> <strong><?php echo $assignedCount; ?></strong>
            </div>
            <div class="exec-metric-tag">
                <span>Vacant:</span> <strong><?php echo $vacantCount; ?></strong>
            </div>
            <a href="<?php echo BASE_URL; ?>users/organization.php" class="exec-btn-add ms-2" target="_blank" title="View Public Page">
                <i class="fas fa-external-link me-1"></i> Public View
            </a>
        </div>
    </div>

    <!-- ================= RANK 1: PARISH PRIEST ================= -->
    <div class="exec-tier-section">
        <div class="exec-tier-heading">
            <div class="exec-tier-title">
                <span class="rank-badge">Rank 1</span> Parish Priest (Pastoral & Canonical Head)
            </div>
        </div>

        <?php 
        $t1 = $tree['tier1'];
        $t1Occ = $t1['occupants'][0]['full_name'] ?? '';
        $t1Vac = empty($t1Occ);
        ?>
        <div class="grid-single-center">
            <div class="exec-card <?php echo $t1Vac ? 'exec-card-vacant' : ''; ?>" id="card-pos-<?php echo (int)$t1['position_id']; ?>">
                
                <!-- Fixed Top-Right Pinned Action -->
                <div class="exec-card-pinned-actions">
                    <?php if (!$t1Vac): ?>
                        <form method="POST" class="m-0" onsubmit="return confirm('Clear and vacate this position?');">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="vacate_position">
                            <input type="hidden" name="position_id" value="<?php echo (int)$t1['position_id']; ?>">
                            <button type="submit" class="btn-exec-ghost btn-exec-vacate" title="Vacate position">
                                <i class="fas fa-user-xmark me-1"></i> Vacate
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Header -->
                <div class="exec-card-header">
                    <div class="exec-card-role"><?php echo e($t1['title']); ?></div>
                    <span class="exec-status-tag <?php echo $t1Vac ? 'status-tag-vacant' : 'status-tag-active'; ?>">
                        <?php echo $t1Vac ? 'Vacant' : 'Active'; ?>
                    </span>
                </div>

                <!-- Body / Direct Input -->
                <div class="exec-card-body">
                    <?php if (!$t1Vac): ?>
                        <div id="display-<?php echo (int)$t1['position_id']; ?>">
                            <div class="exec-occupant-name"><?php echo e($t1Occ); ?></div>
                            <div class="exec-occupant-desc">Canonical Head & Pastor</div>
                            <button type="button" class="exec-btn-edit-toggle" onclick="toggleEdit(<?php echo (int)$t1['position_id']; ?>)">
                                <i class="fas fa-pencil"></i> Edit Name
                            </button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="exec-direct-input-form" id="form-<?php echo (int)$t1['position_id']; ?>" style="<?php echo !$t1Vac ? 'display: none;' : ''; ?>">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="set_occupant_direct">
                        <input type="hidden" name="position_id" value="<?php echo (int)$t1['position_id']; ?>">
                        <input type="text" name="occupant_name" value="<?php echo e($t1Occ); ?>" class="exec-input-text" placeholder="Enter priest full name (e.g. Rev. Fr. Alberto Cahilig, OMI)" required autofocus>
                        <button type="submit" class="exec-btn-save">Save</button>
                        <?php if (!$t1Vac): ?>
                            <button type="button" class="btn-exec-ghost" onclick="toggleEdit(<?php echo (int)$t1['position_id']; ?>)">Cancel</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="exec-connector-stem"></div>

    <!-- ================= RANK 2: ASSISTANT PRIESTS ================= -->
    <div class="exec-tier-section">
        <div class="exec-tier-heading">
            <div class="exec-tier-title">
                <span class="rank-badge">Rank 2</span> Assistant Priests (Parochial Vicars)
            </div>
            <button type="button" class="exec-btn-add" onclick="toggleNewVicarForm()">
                <i class="fas fa-plus"></i> Add Assistant Priest
            </button>
        </div>

        <!-- Add New Assistant Priest Inline Row (Hidden by default) -->
        <div id="newVicarRow" style="display: none; margin-bottom: 1rem;">
            <form method="POST" class="p-3 bg-white border border-slate-300 rounded-3 shadow-sm d-flex align-items-center gap-2">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="add_assistant_priest">
                <span class="text-xs fw-bold text-uppercase text-secondary">New Vicar:</span>
                <input type="text" name="occupant_name" class="exec-input-text" placeholder="Enter Assistant Priest full name (or leave blank to create vacant slot)" style="max-width: 480px;">
                <button type="submit" class="exec-btn-save">Create Slot</button>
                <button type="button" class="btn-exec-ghost" onclick="toggleNewVicarForm()">Cancel</button>
            </form>
        </div>

        <div class="exec-card-grid grid-cols-multi">
            <?php 
            $t2Positions = $tree['tier2'];
            foreach ($t2Positions as $t2):
                $t2Id = (int)$t2['position_id'];
                $t2Occ = $t2['occupants'][0]['full_name'] ?? '';
                $t2Vac = empty($t2Occ);
                $isAdditionalVicar = empty($t2['is_system_role']);
            ?>
            <div class="exec-card <?php echo $t2Vac ? 'exec-card-vacant' : ''; ?>" id="card-pos-<?php echo $t2Id; ?>">
                
                <!-- Fixed Top-Right Pinned Action -->
                <div class="exec-card-pinned-actions">
                    <?php if (!$t2Vac): ?>
                        <form method="POST" class="m-0" onsubmit="return confirm('Clear and vacate this position?');">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="vacate_position">
                            <input type="hidden" name="position_id" value="<?php echo $t2Id; ?>">
                            <button type="submit" class="btn-exec-ghost btn-exec-vacate" title="Vacate position">
                                <i class="fas fa-user-xmark me-1"></i> Vacate
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($isAdditionalVicar): ?>
                        <form method="POST" class="m-0" onsubmit="return confirm('Remove this extra Assistant Priest slot?');">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="remove_assistant_priest">
                            <input type="hidden" name="position_id" value="<?php echo $t2Id; ?>">
                            <button type="submit" class="btn-exec-ghost btn-exec-remove" title="Remove slot">
                                <i class="fas fa-trash-can"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Header -->
                <div class="exec-card-header">
                    <div class="exec-card-role"><?php echo e($t2['title']); ?></div>
                    <span class="exec-status-tag <?php echo $t2Vac ? 'status-tag-vacant' : 'status-tag-active'; ?>">
                        <?php echo $t2Vac ? 'Vacant' : 'Active'; ?>
                    </span>
                </div>

                <!-- Body / Direct Input -->
                <div class="exec-card-body">
                    <?php if (!$t2Vac): ?>
                        <div id="display-<?php echo $t2Id; ?>">
                            <div class="exec-occupant-name"><?php echo e($t2Occ); ?></div>
                            <div class="exec-occupant-desc">Parochial Vicar</div>
                            <button type="button" class="exec-btn-edit-toggle" onclick="toggleEdit(<?php echo $t2Id; ?>)">
                                <i class="fas fa-pencil"></i> Edit Name
                            </button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="exec-direct-input-form" id="form-<?php echo $t2Id; ?>" style="<?php echo !$t2Vac ? 'display: none;' : ''; ?>">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="set_occupant_direct">
                        <input type="hidden" name="position_id" value="<?php echo $t2Id; ?>">
                        <input type="text" name="occupant_name" value="<?php echo e($t2Occ); ?>" class="exec-input-text" placeholder="Enter Assistant Priest name" required>
                        <button type="submit" class="exec-btn-save">Save</button>
                        <?php if (!$t2Vac): ?>
                            <button type="button" class="btn-exec-ghost" onclick="toggleEdit(<?php echo $t2Id; ?>)">Cancel</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="exec-connector-stem"></div>

    <!-- ================= RANK 3: PARISH SECRETARY ================= -->
    <div class="exec-tier-section">
        <div class="exec-tier-heading">
            <div class="exec-tier-title">
                <span class="rank-badge">Rank 3</span> Parish Secretary (Office & Chancery Operations)
            </div>
        </div>

        <?php 
        $t3 = $tree['tier3'];
        $t3Id = (int)$t3['position_id'];
        $t3Occ = $t3['occupants'][0]['full_name'] ?? '';
        $t3Vac = empty($t3Occ);
        ?>
        <div class="grid-single-center">
            <div class="exec-card <?php echo $t3Vac ? 'exec-card-vacant' : ''; ?>" id="card-pos-<?php echo $t3Id; ?>">
                
                <!-- Fixed Top-Right Pinned Action -->
                <div class="exec-card-pinned-actions">
                    <?php if (!$t3Vac): ?>
                        <form method="POST" class="m-0" onsubmit="return confirm('Clear and vacate this position?');">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="vacate_position">
                            <input type="hidden" name="position_id" value="<?php echo $t3Id; ?>">
                            <button type="submit" class="btn-exec-ghost btn-exec-vacate" title="Vacate position">
                                <i class="fas fa-user-xmark me-1"></i> Vacate
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Header -->
                <div class="exec-card-header">
                    <div class="exec-card-role"><?php echo e($t3['title']); ?></div>
                    <span class="exec-status-tag <?php echo $t3Vac ? 'status-tag-vacant' : 'status-tag-active'; ?>">
                        <?php echo $t3Vac ? 'Vacant' : 'Active'; ?>
                    </span>
                </div>

                <!-- Body / Direct Input -->
                <div class="exec-card-body">
                    <?php if (!$t3Vac): ?>
                        <div id="display-<?php echo $t3Id; ?>">
                            <div class="exec-occupant-name"><?php echo e($t3Occ); ?></div>
                            <div class="exec-occupant-desc">Chancery Administration & Office Head</div>
                            <button type="button" class="exec-btn-edit-toggle" onclick="toggleEdit(<?php echo $t3Id; ?>)">
                                <i class="fas fa-pencil"></i> Edit Name
                            </button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="exec-direct-input-form" id="form-<?php echo $t3Id; ?>" style="<?php echo !$t3Vac ? 'display: none;' : ''; ?>">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="set_occupant_direct">
                        <input type="hidden" name="position_id" value="<?php echo $t3Id; ?>">
                        <input type="text" name="occupant_name" value="<?php echo e($t3Occ); ?>" class="exec-input-text" placeholder="Enter Secretary full name" required>
                        <button type="submit" class="exec-btn-save">Save</button>
                        <?php if (!$t3Vac): ?>
                            <button type="button" class="btn-exec-ghost" onclick="toggleEdit(<?php echo $t3Id; ?>)">Cancel</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="exec-connector-stem"></div>

    <!-- ================= RANK 4: PPC EXECUTIVE BOARD ================= -->
    <div class="exec-tier-section">
        <div class="exec-tier-heading">
            <div class="exec-tier-title">
                <span class="rank-badge">Rank 4</span> Parish Pastoral Council (PPC) Executive Board
            </div>
        </div>

        <div class="exec-card-grid grid-cols-multi">
            <?php 
            foreach ($tree['tier4'] as $p4):
                $p4Id = (int)$p4['position_id'];
                $p4Occ = $p4['occupants'][0]['full_name'] ?? '';
                $p4Vac = empty($p4Occ);
            ?>
            <div class="exec-card <?php echo $p4Vac ? 'exec-card-vacant' : ''; ?>" id="card-pos-<?php echo $p4Id; ?>">
                
                <!-- Fixed Top-Right Pinned Action -->
                <div class="exec-card-pinned-actions">
                    <?php if (!$p4Vac): ?>
                        <form method="POST" class="m-0" onsubmit="return confirm('Clear and vacate this position?');">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="vacate_position">
                            <input type="hidden" name="position_id" value="<?php echo $p4Id; ?>">
                            <button type="submit" class="btn-exec-ghost btn-exec-vacate" title="Vacate position">
                                <i class="fas fa-user-xmark me-1"></i> Vacate
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Header -->
                <div class="exec-card-header">
                    <div class="exec-card-role"><?php echo e($p4['title']); ?></div>
                    <span class="exec-status-tag <?php echo $p4Vac ? 'status-tag-vacant' : 'status-tag-active'; ?>">
                        <?php echo $p4Vac ? 'Vacant' : 'Active'; ?>
                    </span>
                </div>

                <!-- Body / Direct Input -->
                <div class="exec-card-body">
                    <?php if (!$p4Vac): ?>
                        <div id="display-<?php echo $p4Id; ?>">
                            <div class="exec-occupant-name"><?php echo e($p4Occ); ?></div>
                            <div class="exec-occupant-desc">Council Officer</div>
                            <button type="button" class="exec-btn-edit-toggle" onclick="toggleEdit(<?php echo $p4Id; ?>)">
                                <i class="fas fa-pencil"></i> Edit Name
                            </button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="exec-direct-input-form" id="form-<?php echo $p4Id; ?>" style="<?php echo !$p4Vac ? 'display: none;' : ''; ?>">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="set_occupant_direct">
                        <input type="hidden" name="position_id" value="<?php echo $p4Id; ?>">
                        <input type="text" name="occupant_name" value="<?php echo e($p4Occ); ?>" class="exec-input-text" placeholder="Enter officer full name" required>
                        <button type="submit" class="exec-btn-save">Save</button>
                        <?php if (!$p4Vac): ?>
                            <button type="button" class="btn-exec-ghost" onclick="toggleEdit(<?php echo $p4Id; ?>)">Cancel</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="exec-connector-stem"></div>

    <!-- ================= RANK 5: MINISTRY COORDINATORS ================= -->
    <div class="exec-tier-section">
        <div class="exec-tier-heading">
            <div class="exec-tier-title">
                <span class="rank-badge">Rank 5</span> Ministry Coordinators (Dynamic Apostolates)
            </div>
            <button type="button" class="exec-btn-add" onclick="openMinistryModal(0, '', '')">
                <i class="fas fa-plus"></i> Add Ministry Role
            </button>
        </div>

        <div class="exec-card-grid grid-cols-multi">
            <?php 
            foreach ($tree['tier5'] as $p5):
                $p5Id = (int)$p5['position_id'];
                $p5Occ = $p5['occupants'][0]['full_name'] ?? '';
                $p5Vac = empty($p5Occ);
            ?>
            <div class="exec-card <?php echo $p5Vac ? 'exec-card-vacant' : ''; ?>" id="card-pos-<?php echo $p5Id; ?>">
                
                <!-- Fixed Top-Right Pinned Action -->
                <div class="exec-card-pinned-actions">
                    <?php if (!$p5Vac): ?>
                        <form method="POST" class="m-0" onsubmit="return confirm('Clear and vacate this position?');">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="vacate_position">
                            <input type="hidden" name="position_id" value="<?php echo $p5Id; ?>">
                            <button type="submit" class="btn-exec-ghost btn-exec-vacate" title="Vacate position">
                                <i class="fas fa-user-xmark me-1"></i> Vacate
                            </button>
                        </form>
                    <?php endif; ?>

                    <button type="button" class="btn-exec-ghost" onclick="openMinistryModal(<?php echo $p5Id; ?>, '<?php echo addslashes($p5['title']); ?>', '<?php echo addslashes($p5Occ); ?>')" title="Rename Ministry">
                        <i class="fas fa-gear"></i>
                    </button>

                    <form method="POST" class="m-0" onsubmit="return confirm('Archive this custom ministry role?');">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="archive_ministry">
                        <input type="hidden" name="position_id" value="<?php echo $p5Id; ?>">
                        <button type="submit" class="btn-exec-ghost btn-exec-remove" title="Archive Role">
                            <i class="fas fa-trash-can"></i>
                        </button>
                    </form>
                </div>

                <!-- Header -->
                <div class="exec-card-header">
                    <div class="exec-card-role"><?php echo e($p5['title']); ?></div>
                    <span class="exec-status-tag <?php echo $p5Vac ? 'status-tag-vacant' : 'status-tag-active'; ?>">
                        <?php echo $p5Vac ? 'Vacant' : 'Active'; ?>
                    </span>
                </div>

                <!-- Body / Direct Input -->
                <div class="exec-card-body">
                    <?php if (!$p5Vac): ?>
                        <div id="display-<?php echo $p5Id; ?>">
                            <div class="exec-occupant-name"><?php echo e($p5Occ); ?></div>
                            <div class="exec-occupant-desc">Ministry Coordinator</div>
                            <button type="button" class="exec-btn-edit-toggle" onclick="toggleEdit(<?php echo $p5Id; ?>)">
                                <i class="fas fa-pencil"></i> Edit Coordinator
                            </button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="exec-direct-input-form" id="form-<?php echo $p5Id; ?>" style="<?php echo !$p5Vac ? 'display: none;' : ''; ?>">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="set_occupant_direct">
                        <input type="hidden" name="position_id" value="<?php echo $p5Id; ?>">
                        <input type="text" name="occupant_name" value="<?php echo e($p5Occ); ?>" class="exec-input-text" placeholder="Enter coordinator full name" required>
                        <button type="submit" class="exec-btn-save">Save</button>
                        <?php if (!$p5Vac): ?>
                            <button type="button" class="btn-exec-ghost" onclick="toggleEdit(<?php echo $p5Id; ?>)">Cancel</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<!-- Minimalist Ministry Modal (One input for title, one for coordinator) -->
<div class="modal fade" id="ministryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form method="POST" class="modal-content rounded-3 border-0 shadow">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="save_ministry">
            <input type="hidden" name="position_id" id="modalMinistryPosId" value="0">

            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold" id="modalMinistryTitle">Ministry Coordinator Role</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-3">
                <div class="mb-2.5">
                    <label class="form-label text-xs fw-bold text-uppercase text-secondary">Ministry Name</label>
                    <input type="text" name="title" id="inputMinistryName" class="exec-input-text w-100" required placeholder="e.g. Ministry of Ushers">
                </div>
                <div>
                    <label class="form-label text-xs fw-bold text-uppercase text-secondary">Coordinator Name (Optional)</label>
                    <input type="text" name="occupant_name" id="inputMinistryCoordinator" class="exec-input-text w-100" placeholder="Enter coordinator name">
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn-exec-ghost" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="exec-btn-save">Save Role</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleEdit(posId) {
    var display = document.getElementById('display-' + posId);
    var form = document.getElementById('form-' + posId);
    if (!display || !form) return;

    if (form.style.display === 'none') {
        display.style.display = 'none';
        form.style.display = 'flex';
        var input = form.querySelector('input[name="occupant_name"]');
        if (input) input.focus();
    } else {
        form.style.display = 'none';
        display.style.display = 'block';
    }
}

function toggleNewVicarForm() {
    var row = document.getElementById('newVicarRow');
    if (!row) return;
    row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'block' : 'none';
    if (row.style.display === 'block') {
        var inp = row.querySelector('input[name="occupant_name"]');
        if (inp) inp.focus();
    }
}

function openMinistryModal(posId, title, occupant) {
    document.getElementById('modalMinistryPosId').value = posId || 0;
    document.getElementById('inputMinistryName').value = title || '';
    document.getElementById('inputMinistryCoordinator').value = occupant || '';
    document.getElementById('modalMinistryTitle').textContent = posId ? 'Edit Ministry Role' : 'Add Ministry Role';
    var modal = new bootstrap.Modal(document.getElementById('ministryModal'));
    modal.show();
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
