<?php
/**
 * Parish Organizational Hierarchy Management
 * Administrative interface to manage clergy appointments, PPC officers,
 * dynamic ministry coordinators, and term assignments.
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

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'assign_member') {
            $posId = (int)($_POST['position_id'] ?? 0);
            $memberId = (int)($_POST['member_id'] ?? 0);
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $endDate = trim((string)($_POST['end_date'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($posId <= 0 || $memberId <= 0) {
                throw new DomainException('Please select both a position and a personnel member.');
            }

            $orgService->assignMember($posId, $memberId, $startDate, $endDate, $notes, (int)$_SESSION['user_id']);
            $success = 'Personnel member successfully appointed to role.';
        } elseif ($action === 'unassign_member') {
            $assignmentId = (int)($_POST['assignment_id'] ?? 0);
            $reason = trim((string)($_POST['vacate_reason'] ?? ''));

            if ($assignmentId <= 0) {
                throw new DomainException('Invalid assignment identifier.');
            }

            $orgService->unassignMember($assignmentId, (int)$_SESSION['user_id'], $reason);
            $success = 'Position vacated successfully. Role is now marked as Vacant.';
        } elseif ($action === 'save_ministry') {
            $posId = (int)($_POST['position_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $displayOrder = (int)($_POST['display_order'] ?? 0);

            if ($title === '') {
                throw new DomainException('Ministry role title is required.');
            }

            if ($posId > 0) {
                $orgService->updateMinistryRole($posId, $title, $description, $displayOrder, (int)$_SESSION['user_id']);
                $success = 'Dynamic ministry role updated successfully.';
            } else {
                $orgService->createMinistryRole($title, $description, $displayOrder, (int)$_SESSION['user_id']);
                $success = 'New dynamic ministry coordinator role created successfully.';
            }
        } elseif ($action === 'archive_ministry') {
            $posId = (int)($_POST['position_id'] ?? 0);
            if ($posId <= 0) {
                throw new DomainException('Invalid position identifier.');
            }

            // OrganizationService enforces strict guardrail on Ranks 1 to 4
            $orgService->archiveMinistryRole($posId, (int)$_SESSION['user_id']);
            $success = 'Ministry role archived successfully.';
        } elseif ($action === 'save_member') {
            $memberData = [
                'member_id' => (int)($_POST['member_id'] ?? 0),
                'title_prefix' => trim((string)($_POST['title_prefix'] ?? '')),
                'full_name' => trim((string)($_POST['full_name'] ?? '')),
                'email' => trim((string)($_POST['email'] ?? '')),
                'phone' => trim((string)($_POST['phone'] ?? '')),
                'bio' => trim((string)($_POST['bio'] ?? '')),
                'user_id' => !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null,
            ];

            $newMemberId = $orgService->saveMember($memberData, (int)$_SESSION['user_id']);
            $success = 'Personnel profile saved successfully.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Reload fresh tree and data
$tree = $orgService->getHierarchyTree();
$allPositions = $orgService->getPositions(false);
$allMembers = $orgService->getMembers(false);

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

// Fetch users for member linking dropdown
$userList = [];
$uRes = $conn->query("SELECT id, fullname, email, role FROM users WHERE status = 'active' ORDER BY fullname ASC LIMIT 200");
if ($uRes) {
    while ($uRow = $uRes->fetch_assoc()) {
        $userList[] = $uRow;
    }
    $uRes->close();
}

include __DIR__ . '/../templates/header.php';
?>

<style>
/* --- Admin Hierarchy Styling --- */
.org-admin-wrap {
    max-width: 1400px;
    margin: 0 auto 3rem auto;
}

/* Metric Cards */
.org-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.25rem;
    margin-bottom: 2rem;
}

.org-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.25rem 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
}

.org-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.stat-icon-gold { background: #fef3c7; color: #b45309; }
.stat-icon-green { background: #dcfce7; color: #15803d; }
.stat-icon-amber { background: #ffedd5; color: #c2410c; }
.stat-icon-indigo { background: #e0e7ff; color: #4338ca; }

.org-stat-val {
    font-size: 1.65rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
}

.org-stat-label {
    font-size: 0.825rem;
    font-weight: 600;
    color: #64748b;
    margin-top: 0.25rem;
}

/* Navigation Tabs & Actions */
.org-toolbar {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 0.85rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
}

.org-nav-pills {
    display: flex;
    gap: 0.5rem;
}

.org-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.55rem 1.15rem;
    font-size: 0.875rem;
    font-weight: 600;
    border-radius: 10px;
    border: 1px solid transparent;
    background: transparent;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s ease;
}

.org-tab-btn:hover {
    color: #1e293b;
    background: #f1f5f9;
}

.org-tab-btn.active {
    background: #2e3a2d;
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(46, 58, 45, 0.25);
}

.org-action-group {
    display: flex;
    gap: 0.75rem;
}

/* --- Visual Tree View in Admin Mode --- */
.org-tree-admin {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    padding: 1.5rem 0;
}

.org-connector-stem {
    width: 2px;
    height: 36px;
    background: #cbd5e1;
    margin: 0 auto;
    position: relative;
}

.org-connector-stem::after {
    content: '';
    position: absolute;
    bottom: -3px;
    left: -3px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #94a3b8;
}

.org-branch-wrap {
    width: 100%;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.org-branch-bar {
    width: 80%;
    max-width: 960px;
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

.org-admin-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
    padding: 1.35rem 1.5rem;
    position: relative;
    display: flex;
    flex-direction: column;
    transition: all 0.2s;
}

.org-admin-card:hover {
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.07);
}

.org-card-r1 { width: 100%; max-width: 500px; border-top: 4px solid #c89b3c; }
.org-card-r2 { width: 100%; max-width: 460px; border-top: 4px solid #0d9488; }
.org-card-r3 { width: 100%; max-width: 460px; border-top: 4px solid #3b82f6; }
.org-card-r4 { border-top: 4px solid #4f46e5; }
.org-card-r5 { border-top: 4px solid #059669; }

.card-vacant-admin {
    background: #fafafa;
    border: 2px dashed #cbd5e1 !important;
}

.org-card-actions {
    margin-top: 1rem;
    padding-top: 0.85rem;
    border-top: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.5rem;
}

.btn-pill-sm {
    font-size: 0.775rem;
    font-weight: 600;
    padding: 0.35rem 0.75rem;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.15s;
}

.btn-pill-primary { background: #f1f5f9; color: #1e293b; border-color: #e2e8f0; }
.btn-pill-primary:hover { background: #e2e8f0; color: #0f172a; }

.btn-pill-assign { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
.btn-pill-assign:hover { background: #d1fae5; color: #065f46; }

.btn-pill-vacate { background: #fff1f2; color: #be123c; border-color: #fecdd3; }
.btn-pill-vacate:hover { background: #ffe4e6; color: #9f1239; }

.btn-pill-locked {
    background: #f8fafc;
    color: #94a3b8;
    border-color: #e2e8f0;
    cursor: not-allowed;
}

/* Grids for Rank 4 & 5 */
.admin-grid-rank4 {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.25rem;
    width: 100%;
}

.admin-grid-rank5 {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.25rem;
    width: 100%;
}

@media (max-width: 1100px) {
    .org-stat-grid { grid-template-columns: repeat(2, 1fr); }
    .admin-grid-rank4 { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 640px) {
    .org-stat-grid { grid-template-columns: 1fr; }
    .admin-grid-rank4 { grid-template-columns: 1fr; }
    .admin-grid-rank5 { grid-template-columns: 1fr; }
    .org-toolbar { flex-direction: column; align-items: stretch; }
    .org-action-group { flex-direction: column; }
}
</style>

<div class="org-admin-wrap">

    <!-- Flash Alerts -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm mb-4" role="alert">
            <i class="fas fa-circle-check me-2"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm mb-4" role="alert">
            <i class="fas fa-triangle-exclamation me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Metrics -->
    <div class="org-stat-grid">
        <div class="org-stat-card">
            <div class="org-stat-icon stat-icon-gold"><i class="fas fa-sitemap"></i></div>
            <div>
                <div class="org-stat-val"><?php echo $totalRoles; ?></div>
                <div class="org-stat-label">Defined Roles (5 Tiers)</div>
            </div>
        </div>
        <div class="org-stat-card">
            <div class="org-stat-icon stat-icon-green"><i class="fas fa-user-check"></i></div>
            <div>
                <div class="org-stat-val"><?php echo $assignedCount; ?></div>
                <div class="org-stat-label">Active Appointments</div>
            </div>
        </div>
        <div class="org-stat-card">
            <div class="org-stat-icon stat-icon-amber"><i class="fas fa-hourglass-half"></i></div>
            <div>
                <div class="org-stat-val"><?php echo $vacantCount; ?></div>
                <div class="org-stat-label">Vacant Positions</div>
            </div>
        </div>
        <div class="org-stat-card">
            <div class="org-stat-icon stat-icon-indigo"><i class="fas fa-hands-praying"></i></div>
            <div>
                <div class="org-stat-val"><?php echo $dynamicMinistriesCount; ?></div>
                <div class="org-stat-label">Custom Ministries (Tier 5)</div>
            </div>
        </div>
    </div>

    <!-- Toolbar: View Tabs & Actions -->
    <div class="org-toolbar">
        <div class="org-nav-pills">
            <button class="org-tab-btn active" id="btnTabTree" type="button" onclick="switchOrgTab('tree')">
                <i class="fas fa-diagram-project"></i> Visual Tree View
            </button>
            <button class="org-tab-btn" id="btnTabTable" type="button" onclick="switchOrgTab('table')">
                <i class="fas fa-list-check"></i> Position Registry
            </button>
            <button class="org-tab-btn" id="btnTabMembers" type="button" onclick="switchOrgTab('members')">
                <i class="fas fa-users"></i> Personnel Directory (<?php echo count($allMembers); ?>)
            </button>
        </div>
        <div class="org-action-group">
            <button class="btn btn-outline-dark rounded-pill px-3 py-2 btn-sm fw-semibold" type="button" onclick="openMemberModal(0)">
                <i class="fas fa-user-plus me-1"></i> New Leader Profile
            </button>
            <button class="btn btn-success rounded-pill px-3 py-2 btn-sm fw-semibold" type="button" onclick="openMinistryModal(0)">
                <i class="fas fa-plus me-1"></i> Add Ministry Role
            </button>
        </div>
    </div>

    <!-- TAB 1: VISUAL TREE VIEW -->
    <div id="tabTreeView" class="org-tab-content">
        <div class="org-tree-admin">

            <!-- RANK 1: PARISH PRIEST -->
            <div class="text-center mb-2">
                <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold text-uppercase" style="font-size: 0.75rem;">
                    <i class="fas fa-crown me-1"></i> Rank 1 &bull; Parish Priest (Single Occupant)
                </span>
            </div>

            <?php 
            $t1 = $tree['tier1']; 
            $t1Occ = $t1['occupants'][0] ?? null;
            $t1Vac = empty($t1Occ);
            ?>
            <div class="org-admin-card org-card-r1 <?php echo $t1Vac ? 'card-vacant-admin' : ''; ?>">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold fs-6 text-dark"><?php echo e($t1['title']); ?></span>
                    <span class="badge <?php echo $t1Vac ? 'bg-warning text-dark' : 'bg-success'; ?> rounded-pill">
                        <?php echo $t1Vac ? 'Vacant' : 'Assigned'; ?>
                    </span>
                </div>

                <?php if (!$t1Vac): ?>
                    <div class="d-flex align-items-center gap-3 my-2">
                        <div class="rounded-circle bg-warning-subtle text-warning-emphasis d-flex align-items-center justify-content-center fw-bold fs-5" style="width: 52px; height: 52px;">
                            <?php echo strtoupper(substr($t1Occ['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div class="fw-bold text-dark fs-6"><?php echo e($t1Occ['display_name']); ?></div>
                            <div class="small text-muted"><?php echo e($t1Occ['email'] ?: 'No email registered'); ?></div>
                            <?php if (!empty($t1Occ['start_date'])): ?>
                                <div class="small text-secondary"><i class="fas fa-calendar-check me-1"></i> Since: <?php echo date('M d, Y', strtotime($t1Occ['start_date'])); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-muted small py-3 text-center">
                        <i class="fas fa-user-clock fs-4 d-block mb-1 text-secondary"></i>
                        No canonical priest currently assigned.
                    </div>
                <?php endif; ?>

                <div class="org-card-actions">
                    <button type="button" class="btn-pill-sm btn-pill-assign" onclick="openAssignModal(<?php echo (int)$t1['position_id']; ?>, '<?php echo addslashes($t1['title']); ?>')">
                        <i class="fas fa-user-pen"></i> <?php echo $t1Vac ? 'Appoint Priest' : 'Reassign'; ?>
                    </button>
                    <?php if (!$t1Vac): ?>
                    <button type="button" class="btn-pill-sm btn-pill-vacate" onclick="openVacateModal(<?php echo (int)$t1Occ['assignment_id']; ?>, '<?php echo addslashes($t1['title']); ?>', '<?php echo addslashes($t1Occ['display_name']); ?>')">
                        <i class="fas fa-user-minus"></i> Vacate
                    </button>
                    <?php endif; ?>
                    <span class="btn-pill-sm btn-pill-locked" title="System core role cannot be deleted">
                        <i class="fas fa-lock"></i> Fixed System Role
                    </span>
                </div>
            </div>

            <!-- Stem 1 -> 2 -->
            <div class="org-connector-stem"></div>

            <!-- RANK 2: PAROCHIAL VICAR -->
            <div class="text-center mb-2">
                <span class="badge bg-info text-dark px-3 py-2 rounded-pill fw-bold text-uppercase" style="font-size: 0.75rem;">
                    <i class="fas fa-church me-1"></i> Rank 2 &bull; Parochial Vicar (0 or More Occupants)
                </span>
            </div>

            <?php 
            foreach ($tree['tier2'] as $t2):
                $t2Occs = $t2['occupants'] ?? [];
                $t2Vac = empty($t2Occs);
            ?>
            <div class="org-admin-card org-card-r2 <?php echo $t2Vac ? 'card-vacant-admin' : ''; ?>">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold fs-6 text-dark"><?php echo e($t2['title']); ?></span>
                    <span class="badge <?php echo $t2Vac ? 'bg-warning text-dark' : 'bg-success'; ?> rounded-pill">
                        <?php echo $t2Vac ? 'Vacant' : 'Assigned (' . count($t2Occs) . ')'; ?>
                    </span>
                </div>

                <?php if (!$t2Vac): ?>
                    <?php foreach ($t2Occs as $occ): ?>
                    <div class="d-flex align-items-center gap-3 my-2 pb-2 border-bottom">
                        <div class="rounded-circle bg-teal-subtle text-teal d-flex align-items-center justify-content-center fw-bold fs-5" style="width: 48px; height: 48px; background: #ccfbf1; color: #0f766e;">
                            <?php echo strtoupper(substr($occ['full_name'], 0, 1)); ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-bold text-dark"><?php echo e($occ['display_name']); ?></div>
                            <div class="small text-muted"><?php echo e($occ['email'] ?: 'No email registered'); ?></div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="openVacateModal(<?php echo (int)$occ['assignment_id']; ?>, '<?php echo addslashes($t2['title']); ?>', '<?php echo addslashes($occ['display_name']); ?>')">
                            Vacate
                        </button>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted small py-2 text-center">
                        No assistant priest assigned.
                    </div>
                <?php endif; ?>

                <div class="org-card-actions">
                    <button type="button" class="btn-pill-sm btn-pill-assign" onclick="openAssignModal(<?php echo (int)$t2['position_id']; ?>, '<?php echo addslashes($t2['title']); ?>')">
                        <i class="fas fa-user-pen"></i> Appoint Vicar
                    </button>
                    <span class="btn-pill-sm btn-pill-locked" title="System core role cannot be deleted">
                        <i class="fas fa-lock"></i> Fixed System Role
                    </span>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Stem 2 -> 3 -->
            <div class="org-connector-stem"></div>

            <!-- RANK 3: PARISH SECRETARY -->
            <div class="text-center mb-2">
                <span class="badge bg-primary px-3 py-2 rounded-pill fw-bold text-uppercase" style="font-size: 0.75rem;">
                    <i class="fas fa-briefcase me-1"></i> Rank 3 &bull; Parish Secretary (Office Operations)
                </span>
            </div>

            <?php 
            $t3 = $tree['tier3']; 
            $t3Occ = $t3['occupants'][0] ?? null;
            $t3Vac = empty($t3Occ);
            ?>
            <div class="org-admin-card org-card-r3 <?php echo $t3Vac ? 'card-vacant-admin' : ''; ?>">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold fs-6 text-dark"><?php echo e($t3['title']); ?></span>
                    <span class="badge <?php echo $t3Vac ? 'bg-warning text-dark' : 'bg-success'; ?> rounded-pill">
                        <?php echo $t3Vac ? 'Vacant' : 'Assigned'; ?>
                    </span>
                </div>

                <?php if (!$t3Vac): ?>
                    <div class="d-flex align-items-center gap-3 my-2">
                        <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center fw-bold fs-5" style="width: 48px; height: 48px;">
                            <?php echo strtoupper(substr($t3Occ['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div class="fw-bold text-dark fs-6"><?php echo e($t3Occ['display_name']); ?></div>
                            <div class="small text-muted"><?php echo e($t3Occ['email'] ?: 'No email registered'); ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-muted small py-2 text-center">
                        Chancery secretary role is currently vacant.
                    </div>
                <?php endif; ?>

                <div class="org-card-actions">
                    <button type="button" class="btn-pill-sm btn-pill-assign" onclick="openAssignModal(<?php echo (int)$t3['position_id']; ?>, '<?php echo addslashes($t3['title']); ?>')">
                        <i class="fas fa-user-pen"></i> <?php echo $t3Vac ? 'Appoint Secretary' : 'Reassign'; ?>
                    </button>
                    <?php if (!$t3Vac): ?>
                    <button type="button" class="btn-pill-sm btn-pill-vacate" onclick="openVacateModal(<?php echo (int)$t3Occ['assignment_id']; ?>, '<?php echo addslashes($t3['title']); ?>', '<?php echo addslashes($t3Occ['display_name']); ?>')">
                        <i class="fas fa-user-minus"></i> Vacate
                    </button>
                    <?php endif; ?>
                    <span class="btn-pill-sm btn-pill-locked" title="System core role cannot be deleted">
                        <i class="fas fa-lock"></i> Fixed System Role
                    </span>
                </div>
            </div>

            <!-- Stem 3 -> 4 -->
            <div class="org-connector-stem"></div>

            <!-- RANK 4: PPC EXECUTIVE BOARD -->
            <div class="org-branch-wrap">
                <div class="org-branch-bar"></div>
                <div class="text-center mb-3">
                    <span class="badge bg-indigo px-3 py-2 rounded-pill fw-bold text-uppercase text-white" style="font-size: 0.75rem; background: #4f46e5;">
                        <i class="fas fa-users-gear me-1"></i> Rank 4 &bull; PPC Executive Board (Side-by-Side Peer Group)
                    </span>
                </div>

                <div class="admin-grid-rank4">
                    <?php 
                    foreach ($tree['tier4'] as $p4):
                        $occ = $p4['occupants'][0] ?? null;
                        $p4Vac = empty($occ);
                    ?>
                    <div class="org-admin-card org-card-r4 <?php echo $p4Vac ? 'card-vacant-admin' : ''; ?>">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold text-dark small"><?php echo e($p4['title']); ?></span>
                            <span class="badge <?php echo $p4Vac ? 'bg-warning text-dark' : 'bg-success'; ?> rounded-pill" style="font-size: 0.7rem;">
                                <?php echo $p4Vac ? 'Vacant' : 'Active'; ?>
                            </span>
                        </div>

                        <?php if (!$p4Vac): ?>
                            <div class="d-flex align-items-center gap-2 my-2">
                                <div class="rounded-circle bg-light text-indigo fw-bold d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; border: 1px solid #e0e7ff; color: #4338ca;">
                                    <?php echo strtoupper(substr($occ['full_name'], 0, 1)); ?>
                                </div>
                                <div style="min-width: 0;">
                                    <div class="fw-bold text-dark text-truncate" style="font-size: 0.9rem;"><?php echo e($occ['display_name']); ?></div>
                                    <div class="text-muted text-truncate" style="font-size: 0.75rem;"><?php echo e($occ['email'] ?: 'No email'); ?></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-muted small py-2 text-center" style="font-size: 0.8rem;">
                                Vacant Council Seat
                            </div>
                        <?php endif; ?>

                        <div class="org-card-actions">
                            <button type="button" class="btn-pill-sm btn-pill-assign" onclick="openAssignModal(<?php echo (int)$p4['position_id']; ?>, '<?php echo addslashes($p4['title']); ?>')">
                                <i class="fas fa-user-pen"></i> Assign
                            </button>
                            <?php if (!$p4Vac): ?>
                            <button type="button" class="btn-pill-sm btn-pill-vacate" onclick="openVacateModal(<?php echo (int)$occ['assignment_id']; ?>, '<?php echo addslashes($p4['title']); ?>', '<?php echo addslashes($occ['display_name']); ?>')">
                                <i class="fas fa-user-minus"></i>
                            </button>
                            <?php endif; ?>
                            <span class="btn-pill-sm btn-pill-locked" title="Fixed System Role">
                                <i class="fas fa-lock"></i>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Stem 4 -> 5 -->
            <div class="org-connector-stem" style="height: 48px; margin-top: 1.5rem;"></div>

            <!-- RANK 5: MINISTRY COORDINATORS -->
            <div class="org-branch-wrap">
                <div class="org-branch-bar"></div>
                <div class="text-center mb-3">
                    <span class="badge bg-success px-3 py-2 rounded-pill fw-bold text-uppercase" style="font-size: 0.75rem;">
                        <i class="fas fa-people-group me-1"></i> Rank 5 &bull; Dynamic Ministry Coordinators (Admins Can Create / Reorder / Archive)
                    </span>
                </div>

                <div class="admin-grid-rank5">
                    <?php 
                    foreach ($tree['tier5'] as $p5):
                        $occ = $p5['occupants'][0] ?? null;
                        $p5Vac = empty($occ);
                    ?>
                    <div class="org-admin-card org-card-r5 <?php echo $p5Vac ? 'card-vacant-admin' : ''; ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="fw-bold text-dark" style="font-size: 0.95rem; line-height: 1.3;">
                                <?php echo e($p5['title']); ?>
                            </div>
                            <span class="badge <?php echo $p5Vac ? 'bg-warning text-dark' : 'bg-success'; ?> rounded-pill ms-2" style="font-size: 0.7rem;">
                                <?php echo $p5Vac ? 'Vacant' : 'Assigned'; ?>
                            </span>
                        </div>

                        <?php if (!empty($p5['description'])): ?>
                            <div class="text-muted small mb-2" style="font-size: 0.8rem;"><?php echo e($p5['description']); ?></div>
                        <?php endif; ?>

                        <?php if (!$p5Vac): ?>
                            <div class="d-flex align-items-center gap-2 my-2">
                                <div class="rounded-circle bg-success-subtle text-success fw-bold d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($occ['full_name'], 0, 1)); ?>
                                </div>
                                <div style="min-width: 0;">
                                    <div class="fw-bold text-dark text-truncate" style="font-size: 0.9rem;"><?php echo e($occ['display_name']); ?></div>
                                    <div class="text-muted text-truncate" style="font-size: 0.75rem;"><?php echo e($occ['phone'] ?: ($occ['email'] ?: 'No contact')); ?></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-muted small py-2 text-center" style="font-size: 0.8rem;">
                                <i class="fas fa-user-plus me-1"></i> Vacant / Coordinator needed
                            </div>
                        <?php endif; ?>

                        <div class="org-card-actions">
                            <button type="button" class="btn-pill-sm btn-pill-assign" onclick="openAssignModal(<?php echo (int)$p5['position_id']; ?>, '<?php echo addslashes($p5['title']); ?>')">
                                <i class="fas fa-user-pen"></i> Assign
                            </button>
                            <?php if (!$p5Vac): ?>
                            <button type="button" class="btn-pill-sm btn-pill-vacate" onclick="openVacateModal(<?php echo (int)$occ['assignment_id']; ?>, '<?php echo addslashes($p5['title']); ?>', '<?php echo addslashes($occ['display_name']); ?>')">
                                <i class="fas fa-user-minus"></i>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn-pill-sm btn-pill-primary" onclick="openMinistryModal(<?php echo (int)$p5['position_id']; ?>, '<?php echo addslashes($p5['title']); ?>', '<?php echo addslashes($p5['description'] ?? ''); ?>', <?php echo (int)$p5['display_order']; ?>)">
                                <i class="fas fa-pen"></i> Edit
                            </button>
                            <button type="button" class="btn-pill-sm btn-pill-vacate" onclick="openArchiveModal(<?php echo (int)$p5['position_id']; ?>, '<?php echo addslashes($p5['title']); ?>')">
                                <i class="fas fa-box-archive"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- TAB 2: POSITION REGISTRY TABLE -->
    <div id="tabTableView" class="org-tab-content" style="display: none;">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr style="font-size: 0.825rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">
                                <th class="ps-4">Rank Tier</th>
                                <th>Position Title</th>
                                <th>Role Type</th>
                                <th>Assigned Member(s)</th>
                                <th>Occupancy</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allPositions as $pos): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="badge rounded-pill <?php 
                                        echo match((int)$pos['rank_level']) {
                                            1 => 'bg-warning text-dark',
                                            2 => 'bg-info text-dark',
                                            3 => 'bg-primary',
                                            4 => 'bg-indigo text-white',
                                            default => 'bg-success',
                                        }; 
                                    ?>">
                                        Tier <?php echo (int)$pos['rank_level']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo e($pos['title']); ?></div>
                                    <?php if (!empty($pos['description'])): ?>
                                        <div class="text-muted small"><?php echo e($pos['description']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)$pos['is_system_role'] === 1): ?>
                                        <span class="badge bg-secondary-subtle text-secondary border"><i class="fas fa-lock me-1"></i> Core System</span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success border"><i class="fas fa-sparkles me-1"></i> Dynamic Custom</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $occCount = (int)$pos['active_occupants_count'];
                                    if ($occCount > 0): 
                                    ?>
                                        <span class="badge bg-success-subtle text-success px-2 py-1"><i class="fas fa-check me-1"></i> <?php echo $occCount; ?> Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis px-2 py-1"><i class="fas fa-hourglass-half me-1"></i> Vacant</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="small text-muted">
                                        Max: <?php echo (int)$pos['max_occupants'] > 1 ? (int)$pos['max_occupants'] : 'Single'; ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary" onclick="openAssignModal(<?php echo (int)$pos['position_id']; ?>, '<?php echo addslashes($pos['title']); ?>')">
                                            <i class="fas fa-user-pen"></i> Assign
                                        </button>
                                        <?php if ((int)$pos['rank_level'] === 5): ?>
                                        <button type="button" class="btn btn-outline-secondary" onclick="openMinistryModal(<?php echo (int)$pos['position_id']; ?>, '<?php echo addslashes($pos['title']); ?>', '<?php echo addslashes($pos['description'] ?? ''); ?>', <?php echo (int)$pos['display_order']; ?>)">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger" onclick="openArchiveModal(<?php echo (int)$pos['position_id']; ?>, '<?php echo addslashes($pos['title']); ?>')">
                                            <i class="fas fa-box-archive"></i>
                                        </button>
                                        <?php else: ?>
                                        <button type="button" class="btn btn-light text-muted" disabled title="Core System Role cannot be modified or deleted">
                                            <i class="fas fa-lock"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 3: PERSONNEL DIRECTORY -->
    <div id="tabMembersView" class="org-tab-content" style="display: none;">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-dark">Personnel & Leadership Directory</h5>
                <button type="button" class="btn btn-dark rounded-pill btn-sm fw-semibold" onclick="openMemberModal(0)">
                    <i class="fas fa-user-plus me-1"></i> Register New Leader
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr style="font-size: 0.825rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">
                                <th class="ps-4">Personnel Member</th>
                                <th>Prefix</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Linked Account</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allMembers as $m): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center fw-bold text-dark" style="width: 42px; height: 42px;">
                                            <?php echo strtoupper(substr($m['full_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?php echo e($m['title_prefix'] ? $m['title_prefix'] . ' ' : '') . e($m['full_name']); ?></div>
                                            <?php if (!empty($m['bio'])): ?>
                                                <div class="text-muted small text-truncate" style="max-width: 250px;"><?php echo e($m['bio']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?php echo e($m['title_prefix'] ?: 'None'); ?></span></td>
                                <td><?php echo e($m['email'] ?: '—'); ?></td>
                                <td><?php echo e($m['phone'] ?: '—'); ?></td>
                                <td>
                                    <?php if (!empty($m['user_id'])): ?>
                                        <span class="badge bg-primary-subtle text-primary">User #<?php echo (int)$m['user_id']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">Unlinked</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-outline-dark rounded-pill px-3" onclick="openMemberModal(<?php echo (int)$m['member_id']; ?>, '<?php echo addslashes($m['title_prefix'] ?? ''); ?>', '<?php echo addslashes($m['full_name']); ?>', '<?php echo addslashes($m['email'] ?? ''); ?>', '<?php echo addslashes($m['phone'] ?? ''); ?>', '<?php echo addslashes($m['bio'] ?? ''); ?>', <?php echo (int)($m['user_id'] ?? 0); ?>)">
                                        <i class="fas fa-pen me-1"></i> Edit Profile
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ================= MODALS ================= -->

<!-- 1. Assign / Reassign Member Modal -->
<div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content rounded-4 border-0 shadow">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="assign_member">
            <input type="hidden" name="position_id" id="assignPositionId" value="0">

            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="assignModalLabel">Appoint Personnel to Role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border rounded-3 small mb-3">
                    Target Role: <strong id="assignRoleTitleBadge" class="text-dark">—</strong>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Select Member / Leader <span class="text-danger">*</span></label>
                    <select name="member_id" id="assignMemberId" class="form-select rounded-3" required>
                        <option value="">-- Select Personnel --</option>
                        <?php foreach ($allMembers as $mem): ?>
                            <option value="<?php echo (int)$mem['member_id']; ?>">
                                <?php echo e(($mem['title_prefix'] ? $mem['title_prefix'] . ' ' : '') . $mem['full_name']); ?> (<?php echo e($mem['email'] ?: 'No email'); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Start Date</label>
                        <input type="date" name="start_date" class="form-control rounded-3" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">End Date (Optional)</label>
                        <input type="date" name="end_date" class="form-control rounded-3">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Appointment Notes / Mandate</label>
                    <textarea name="notes" class="form-control rounded-3" rows="2" placeholder="e.g. Canonical decree #104; 2-year council term."></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success rounded-pill px-4">Confirm Appointment</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Dynamic Ministry Role Modal (Rank 5) -->
<div class="modal fade" id="ministryModal" tabindex="-1" aria-labelledby="ministryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content rounded-4 border-0 shadow">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="save_ministry">
            <input type="hidden" name="position_id" id="ministryPositionId" value="0">

            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="ministryModalLabel">Dynamic Ministry Coordinator Role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    Dynamic ministry roles are classified under <strong>Rank 5</strong>. They can be created, updated, or archived by parish administrators.
                </p>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Ministry / Commission Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" id="ministryTitle" class="form-control rounded-3" required placeholder="e.g. Ministry of Lectors & Commentators">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Description / Mandate</label>
                    <textarea name="description" id="ministryDescription" class="form-control rounded-3" rows="2" placeholder="Liturgical duties, reading assignments, and formation."></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Display Order</label>
                    <input type="number" name="display_order" id="ministryDisplayOrder" class="form-control rounded-3" value="1" min="1">
                    <div class="form-text small">Controls the horizontal grid ordering among ministry coordinators.</div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success rounded-pill px-4" id="ministrySubmitBtn">Save Ministry Role</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Personnel / Member Profile Modal -->
<div class="modal fade" id="memberModal" tabindex="-1" aria-labelledby="memberModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content rounded-4 border-0 shadow">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="save_member">
            <input type="hidden" name="member_id" id="memberProfileId" value="0">

            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="memberModalLabel">Personnel Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label fw-semibold">Title Prefix</label>
                        <select name="title_prefix" id="memberTitlePrefix" class="form-select rounded-3">
                            <option value="">None</option>
                            <option value="Rev. Fr.">Rev. Fr.</option>
                            <option value="Bro.">Bro.</option>
                            <option value="Sis.">Sis.</option>
                            <option value="Ms.">Ms.</option>
                            <option value="Mr.">Mr.</option>
                            <option value="Dr.">Dr.</option>
                            <option value="Engr.">Engr.</option>
                        </select>
                    </div>
                    <div class="col-8">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="memberFullName" class="form-control rounded-3" required placeholder="e.g. Juan Dela Cruz">
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Email Address</label>
                        <input type="email" name="email" id="memberEmail" class="form-control rounded-3" placeholder="leader@sanlorenzoruiz.ph">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Phone Number</label>
                        <input type="text" name="phone" id="memberPhone" class="form-control rounded-3" placeholder="+63 917 ...">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Link Portal User Account (Optional)</label>
                    <select name="user_id" id="memberUserId" class="form-select rounded-3">
                        <option value="">-- Standalone Leader (No Portal Account) --</option>
                        <?php foreach ($userList as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>">
                                #<?php echo (int)$u['id']; ?> - <?php echo e($u['fullname']); ?> (<?php echo e($u['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Biography / Background</label>
                    <textarea name="bio" id="memberBio" class="form-control rounded-3" rows="2" placeholder="Brief pastoral role summary or canonical bio."></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark rounded-pill px-4">Save Leader Profile</button>
            </div>
        </form>
    </div>
</div>

<!-- 4. Confirm Vacate Modal -->
<div class="modal fade" id="vacateModal" tabindex="-1" aria-labelledby="vacateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form method="POST" class="modal-content rounded-4 border-0 shadow">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="unassign_member">
            <input type="hidden" name="assignment_id" id="vacateAssignmentId" value="0">

            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger" id="vacateModalLabel">Vacate Position</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">
                    Are you sure you want to relieve <strong id="vacateMemberName" class="text-dark">—</strong> from the role <strong id="vacateRoleName" class="text-dark">—</strong>?
                </p>
                <p class="small text-muted mb-3">
                    The position will immediately transition to <strong>"Vacant / Pending Appointment"</strong>.
                </p>
                <div class="mb-2">
                    <label class="form-label fw-semibold small">Reason (Optional)</label>
                    <input type="text" name="vacate_reason" class="form-control form-control-sm rounded-3" placeholder="e.g. End of 2-year term, transfer">
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-3 btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger rounded-pill px-3 btn-sm">Vacate Role</button>
            </div>
        </form>
    </div>
</div>

<!-- 5. Confirm Archive Custom Role Modal -->
<div class="modal fade" id="archiveModal" tabindex="-1" aria-labelledby="archiveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form method="POST" class="modal-content rounded-4 border-0 shadow">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="archive_ministry">
            <input type="hidden" name="position_id" id="archivePositionId" value="0">

            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger" id="archiveModalLabel">Archive Role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">
                    Archive custom ministry role: <strong id="archiveRoleTitle" class="text-dark">—</strong>?
                </p>
                <p class="small text-muted mb-0">
                    This will unassign any active coordinators and remove the role from the public org chart.
                </p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-3 btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger rounded-pill px-3 btn-sm">Archive Role</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchOrgTab(tab) {
    document.querySelectorAll('.org-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.org-tab-content').forEach(c => c.style.display = 'none');

    if (tab === 'tree') {
        document.getElementById('btnTabTree').classList.add('active');
        document.getElementById('tabTreeView').style.display = 'block';
    } else if (tab === 'table') {
        document.getElementById('btnTabTable').classList.add('active');
        document.getElementById('tabTableView').style.display = 'block';
    } else if (tab === 'members') {
        document.getElementById('btnTabMembers').classList.add('active');
        document.getElementById('tabMembersView').style.display = 'block';
    }
}

function openAssignModal(positionId, roleTitle) {
    document.getElementById('assignPositionId').value = positionId;
    document.getElementById('assignRoleTitleBadge').textContent = roleTitle;
    document.getElementById('assignMemberId').value = '';
    var modal = new bootstrap.Modal(document.getElementById('assignModal'));
    modal.show();
}

function openVacateModal(assignmentId, roleTitle, memberName) {
    document.getElementById('vacateAssignmentId').value = assignmentId;
    document.getElementById('vacateRoleName').textContent = roleTitle;
    document.getElementById('vacateMemberName').textContent = memberName;
    var modal = new bootstrap.Modal(document.getElementById('vacateModal'));
    modal.show();
}

function openMinistryModal(posId, title, desc, order) {
    document.getElementById('ministryPositionId').value = posId || 0;
    document.getElementById('ministryTitle').value = title || '';
    document.getElementById('ministryDescription').value = desc || '';
    document.getElementById('ministryDisplayOrder').value = order || 1;
    document.getElementById('ministryModalLabel').textContent = posId ? 'Edit Ministry Coordinator Role' : 'Add New Ministry Coordinator Role';
    document.getElementById('ministrySubmitBtn').textContent = posId ? 'Update Role' : 'Create Ministry Role';
    var modal = new bootstrap.Modal(document.getElementById('ministryModal'));
    modal.show();
}

function openArchiveModal(posId, roleTitle) {
    document.getElementById('archivePositionId').value = posId;
    document.getElementById('archiveRoleTitle').textContent = roleTitle;
    var modal = new bootstrap.Modal(document.getElementById('archiveModal'));
    modal.show();
}

function openMemberModal(memberId, prefix, name, email, phone, bio, userId) {
    document.getElementById('memberProfileId').value = memberId || 0;
    document.getElementById('memberTitlePrefix').value = prefix || '';
    document.getElementById('memberFullName').value = name || '';
    document.getElementById('memberEmail').value = email || '';
    document.getElementById('memberPhone').value = phone || '';
    document.getElementById('memberBio').value = bio || '';
    document.getElementById('memberUserId').value = userId || '';
    document.getElementById('memberModalLabel').textContent = memberId ? 'Edit Leader Profile' : 'Register New Leader Profile';
    var modal = new bootstrap.Modal(document.getElementById('memberModal'));
    modal.show();
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
