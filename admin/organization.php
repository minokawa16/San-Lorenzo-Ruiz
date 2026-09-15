<?php
/**
 * Parish Organizational Hierarchy Management
 * Redesigned as a true 5-tier vertical hierarchy flowchart:
 * - Level 1: Parish Priest (Top Tier)
 * - Level 2: Assistant Priests (Directly below Level 1)
 * - Level 3: Secretary & Finance (Directly below Level 2)
 * - Level 4: PPC Officers (Directly below Level 3, branching horizontally)
 * - Level 5: Ministry Coordinators (Directly below Level 4, branching horizontally)
 *
 * Connected with 1.5px brass lines, rank badges (2, 3, 4, 5),
 * compact 220px cards, inline editing, and responsive collapse.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/OrganizationService.php';
require_once __DIR__ . '/../includes/components/org-chart-component.php';

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
        } elseif ($action === 'remove_assistant_priest' || $action === 'delete_position' || $action === 'archive_ministry') {
            $posId = (int)($_POST['position_id'] ?? 0);
            $orgService->deletePosition($posId, (int)$_SESSION['user_id']);
            $success = 'Position or record permanently deleted.';
        } elseif ($action === 'save_position_settings' || $action === 'save_ministry') {
            $posId = (int)($_POST['position_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $occupant = trim((string)($_POST['occupant_name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $displayOrder = (int)($_POST['display_order'] ?? 0);

            if ($posId > 0) {
                $orgService->savePositionSettings($posId, $title, $occupant, $phone, $email, $description, (int)$_SESSION['user_id']);
                $success = 'Position settings and contact details saved.';
            } else {
                if ($title === '') {
                    throw new DomainException('Ministry title is required.');
                }
                $newPosId = $orgService->createMinistryRole($title, $description, $displayOrder, (int)$_SESSION['user_id']);
                if ($occupant !== '' || $phone !== '' || $email !== '' || $description !== '') {
                    $orgService->savePositionSettings($newPosId, $title, $occupant, $phone, $email, $description, (int)$_SESSION['user_id']);
                }
                $success = 'New ministry coordinator card created.';
            }
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

// =========================================================================
// BUILD STRICT 5-TIER HIERARCHY TREE DATA FOR COMPONENT
// 1. Level 1 (Top Tier): Parish Priest
// 2. Level 2: Assistant Priests (Directly below Level 1)
// 3. Level 3: Secretary & Finance (Directly below Level 2)
// 4. Level 4: PPC Officers (Directly below Level 3, branching horizontally)
// 5. Level 5 (Bottom Tier): Ministry Coordinators (Directly below Level 4)
// =========================================================================

// Level 1: Parish Priest
$priestOccupant = $tree['tier1']['occupants'][0]['full_name'] ?? '';
$priestVacant = empty($priestOccupant);
$priestPosId = (int)($tree['tier1']['position_id'] ?? 1);

$level1Nodes = [
    [
        'id' => $priestPosId,
        'level' => 1,
        'role' => $tree['tier1']['title'] ?? 'Parish Priest',
        'name' => $priestOccupant,
        'phone' => $tree['tier1']['occupants'][0]['phone'] ?? '',
        'email' => $tree['tier1']['occupants'][0]['email'] ?? '',
        'status' => $priestVacant ? 'Vacant' : 'Active',
        'is_vacant' => $priestVacant,
        'description' => $tree['tier1']['description'] ?? '',
        'is_system_role' => true,
        'can_vacate' => !$priestVacant,
        'parentId' => null
    ]
];

// Level 2: Assistant Priests (Parochial Vicars)
$level2Nodes = [];
if (!empty($tree['tier2'])) {
    foreach ($tree['tier2'] as $t2) {
        $t2Occ = $t2['occupants'][0]['full_name'] ?? '';
        $t2Vac = empty($t2Occ);
        $level2Nodes[] = [
            'id' => (int)$t2['position_id'],
            'level' => 2,
            'role' => $t2['title'],
            'name' => $t2Occ,
            'phone' => $t2['occupants'][0]['phone'] ?? '',
            'email' => $t2['occupants'][0]['email'] ?? '',
            'status' => $t2Vac ? 'Vacant' : 'Active',
            'is_vacant' => $t2Vac,
            'description' => $t2['description'] ?? '',
            'is_system_role' => !empty($t2['is_system_role']),
            'can_vacate' => !$t2Vac,
            'can_remove' => empty($t2['is_system_role']),
            'parentId' => $priestPosId
        ];
    }
}

// Level 3: Secretary & Finance
$t3 = $tree['tier3'];
$t3Occ = $t3['occupants'][0]['full_name'] ?? '';
$t3Vac = empty($t3Occ);
$secPosId = (int)($t3['position_id'] ?? 3);
$parentForSecretary = !empty($level2Nodes) ? (int)$level2Nodes[0]['id'] : $priestPosId;

$level3Nodes = [
    [
        'id' => $secPosId,
        'level' => 3,
        'role' => $t3['title'] ?? 'Parish Secretary',
        'name' => $t3Occ,
        'phone' => $t3['occupants'][0]['phone'] ?? '',
        'email' => $t3['occupants'][0]['email'] ?? '',
        'status' => $t3Vac ? 'Vacant' : 'Active',
        'is_vacant' => $t3Vac,
        'description' => $t3['description'] ?? '',
        'is_system_role' => true,
        'can_vacate' => !$t3Vac,
        'parentId' => $parentForSecretary
    ]
];

// Level 4: PPC Officers (branching horizontally directly below Level 3)
$level4Nodes = [];
$ppcPresId = null;
if (!empty($tree['tier4'])) {
    foreach ($tree['tier4'] as $p4) {
        $p4Occ = $p4['occupants'][0]['full_name'] ?? '';
        $p4Vac = empty($p4Occ);
        $p4Id = (int)$p4['position_id'];
        if ($ppcPresId === null && stripos($p4['title'], 'President') !== false && stripos($p4['title'], 'Vice') === false) {
            $ppcPresId = $p4Id;
        }
        $level4Nodes[] = [
            'id' => $p4Id,
            'level' => 4,
            'role' => $p4['title'],
            'name' => $p4Occ,
            'phone' => $p4['occupants'][0]['phone'] ?? '',
            'email' => $p4['occupants'][0]['email'] ?? '',
            'status' => $p4Vac ? 'Vacant' : 'Active',
            'is_vacant' => $p4Vac,
            'description' => $p4['description'] ?? '',
            'is_system_role' => true,
            'can_vacate' => !$p4Vac,
            'parentId' => $secPosId
        ];
    }
}
if ($ppcPresId === null && !empty($level4Nodes)) {
    $ppcPresId = (int)$level4Nodes[0]['id'];
}

// Level 5: Ministry Coordinators (branching horizontally directly below Level 4)
$level5Nodes = [];
if (!empty($tree['tier5'])) {
    foreach ($tree['tier5'] as $p5) {
        $p5Occ = $p5['occupants'][0]['full_name'] ?? '';
        $p5Vac = empty($p5Occ);
        $level5Nodes[] = [
            'id' => (int)$p5['position_id'],
            'level' => 5,
            'role' => $p5['title'],
            'name' => $p5Occ,
            'phone' => $p5['occupants'][0]['phone'] ?? '',
            'email' => $p5['occupants'][0]['email'] ?? '',
            'status' => $p5Vac ? 'Vacant' : 'Active',
            'is_vacant' => $p5Vac,
            'description' => $p5['description'] ?? '',
            'is_system_role' => false,
            'is_custom_ministry' => true,
            'can_vacate' => !$p5Vac,
            'parentId' => $ppcPresId
        ];
    }
}

// Assemble the 5-tier map
$orgChart5Tier = [
    1 => [
        'tier_title' => 'Parish Priest',
        'level' => 1,
        'nodes' => $level1Nodes
    ],
    2 => [
        'tier_title' => 'Assistant Priests',
        'level' => 2,
        'nodes' => $level2Nodes
    ],
    3 => [
        'tier_title' => 'Secretary & Finance',
        'level' => 3,
        'nodes' => $level3Nodes
    ],
    4 => [
        'tier_title' => 'PPC Officers',
        'level' => 4,
        'nodes' => $level4Nodes
    ],
    5 => [
        'tier_title' => 'Ministry Coordinators',
        'level' => 5,
        'nodes' => $level5Nodes
    ]
];

include __DIR__ . '/../templates/header.php';
?>

<style>
/* Page-level layout styles for admin organization */
.parish-admin-org-page {
    background-color: #FAF8F3;
    min-height: calc(100vh - 70px);
    margin: -1.5rem -1.5rem 0 -1.5rem;
    padding: 2rem 1.5rem 4rem 1.5rem;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: #16233A;
}

.org-admin-header {
    max-width: 1240px;
    margin: 0 auto 2rem auto;
    background: #FFFFFF;
    border: 1px solid #E5E0D8;
    border-radius: 10px;
    padding: 1.25rem 1.75rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.25rem;
}

.org-admin-title {
    font-family: 'Fraunces', 'Playfair Display', Georgia, serif;
    font-size: 1.5rem;
    font-weight: 700;
    color: #16233A;
    margin: 0 0 0.25rem 0;
    letter-spacing: -0.01em;
}

.org-admin-subtitle {
    font-size: 0.85rem;
    color: #5A6779;
    margin: 0;
}

.org-admin-metrics {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}

.org-metric-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.7rem;
    background: #FAF8F3;
    border: 1px solid #E5E0D8;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    color: #5A6779;
}

.org-metric-pill strong {
    color: #16233A;
}

.btn-org-cta {
    background: #FAF8F3;
    color: #16233A;
    border: 1px solid #DCD5C9;
    border-radius: 6px;
    padding: 0.4rem 0.8rem;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
}

.btn-org-cta:hover {
    background: #FFFFFF;
    border-color: #A9812E;
    color: #A9812E;
}

.btn-org-cta-primary {
    background: #16233A;
    color: #FFFFFF;
    border: 1px solid #16233A;
}

.btn-org-cta-primary:hover {
    background: #243552;
    border-color: #243552;
    color: #FFFFFF;
}

.new-vicar-drawer {
    max-width: 1240px;
    margin: 0 auto 1.5rem auto;
    background: #FFFFFF;
    border: 1px solid #E5E0D8;
    border-radius: 10px;
    padding: 1.25rem 1.5rem;
}
</style>

<div class="parish-admin-org-page">

    <!-- Flash Messages -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm mb-3 py-2 px-3 small mx-auto" style="max-width: 1240px;" role="alert">
            <i class="fas fa-check-circle me-1.5 text-success"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm mb-3 py-2 px-3 small mx-auto" style="max-width: 1240px;" role="alert">
            <i class="fas fa-circle-exclamation me-1.5 text-danger"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Executive Administration Top Header -->
    <div class="org-admin-header">
        <div>
            <h1 class="org-admin-title">Parish Organizational Hierarchy</h1>
            <p class="org-admin-subtitle">Strict 5-tier vertical hierarchy flowchart with brass connector lines, rank badges, and inline editing.</p>
        </div>
        <div class="org-admin-metrics">
            <div class="org-metric-pill">
                <span>Ranks:</span> <strong>5 Tiers</strong>
            </div>
            <div class="org-metric-pill">
                <span>Active:</span> <strong><?php echo $assignedCount; ?></strong>
            </div>
            <div class="org-metric-pill">
                <span>Vacant:</span> <strong><?php echo $vacantCount; ?></strong>
            </div>
            <button type="button" class="btn-org-cta" onclick="toggleNewVicarDrawer()">
                <i class="fas fa-user-plus"></i> Add Assistant Priest
            </button>
            <button type="button" class="btn-org-cta" onclick="openPositionSettings(null)">
                <i class="fas fa-plus"></i> Add Ministry Role
            </button>
        </div>
    </div>

    <!-- Add New Assistant Priest Inline Drawer (Hidden by default) -->
    <div id="newVicarDrawer" class="new-vicar-drawer" style="display: none;">
        <form method="POST" class="d-flex align-items-center gap-3 flex-wrap m-0">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="add_assistant_priest">
            <span class="fw-bold small text-uppercase text-secondary" style="letter-spacing: 0.05em;">New Assistant Priest:</span>
            <input type="text" 
                   name="occupant_name" 
                   class="org-inline-input" 
                   placeholder="Enter priest full name (e.g. Rev. Fr. Mark Anthony Santos, OMI)" 
                   style="max-width: 440px;" 
                   required>
            <button type="submit" class="btn-org-cta btn-org-cta-primary">
                <i class="fas fa-check"></i> Create Slot
            </button>
            <button type="button" class="btn-org-cta" onclick="toggleNewVicarDrawer()">Cancel</button>
        </form>
    </div>

    <!-- ================================================================= -->
    <!-- RENDER STRICT 5-TIER ORGANIZATIONAL CHART COMPONENT -->
    <!-- ================================================================= -->
    <?php 
    renderParishOrgChart5Tier($orgChart5Tier, [
        'editable' => true,
        'csrf_token' => generateCsrfToken()
    ]); 
    ?>

</div>

<!-- Position & Person Settings Modal -->
<div class="modal fade" id="positionSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 460px;">
        <form method="POST" class="modal-content rounded-3 border-0 shadow" style="border-radius: 12px; overflow: hidden;">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="save_position_settings">
            <input type="hidden" name="position_id" id="settingPosId" value="0">

            <div class="modal-header border-0 pb-0 pt-3 px-4" style="background: #FAF8F5;">
                <div class="d-flex align-items-center gap-2">
                    <span class="rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: rgba(169, 129, 46, 0.15); color: #A9812E;">
                        <i class="fas fa-gear"></i>
                    </span>
                    <h6 class="modal-title fw-bold m-0" id="settingModalTitle" style="font-family: 'Fraunces', 'Playfair Display', serif; color: #16233A; font-size: 1.05rem;">
                        Position & Person Settings
                    </h6>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body py-3 px-4">
                <!-- Position Title -->
                <div class="mb-3" id="groupPositionTitle">
                    <label class="form-label text-xs fw-bold text-uppercase text-secondary" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                        Position Title
                    </label>
                    <input type="text" name="title" id="settingPositionTitle" class="form-control form-control-sm" required style="border-radius: 6px; border-color: #DCD5C9;">
                </div>

                <!-- Occupant Name -->
                <div class="mb-3">
                    <label class="form-label text-xs fw-bold text-uppercase text-secondary" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                        Person / Appointee Name
                    </label>
                    <input type="text" name="occupant_name" id="settingOccupantName" class="form-control form-control-sm" placeholder="e.g. Rev. Fr. Mark Anthony Santos, OMI" style="border-radius: 6px; border-color: #DCD5C9;">
                    <div class="form-text text-muted" style="font-size: 0.7rem;">Leave blank to vacate or unassign the position.</div>
                </div>

                <!-- Contact Number (Optional) -->
                <div class="mb-3">
                    <label class="form-label text-xs fw-bold text-uppercase text-secondary" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                        <i class="fas fa-phone me-1 text-muted"></i> Contact Number <span class="fw-normal text-muted text-capitalize">(Optional)</span>
                    </label>
                    <input type="text" name="phone" id="settingPhone" class="form-control form-control-sm" placeholder="e.g. +63 917 123 4567" style="border-radius: 6px; border-color: #DCD5C9;">
                </div>

                <!-- Facebook / Email (Optional) -->
                <div class="mb-3">
                    <label class="form-label text-xs fw-bold text-uppercase text-secondary" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                        <i class="fas fa-share-nodes me-1 text-muted"></i> Facebook / Email <span class="fw-normal text-muted text-capitalize">(Optional)</span>
                    </label>
                    <input type="text" name="email" id="settingEmail" class="form-control form-control-sm" placeholder="e.g. fb.com/profile or name@parish.ph" style="border-radius: 6px; border-color: #DCD5C9;">
                </div>

                <!-- Role Description / Subtitle (Optional) -->
                <div class="mb-2">
                    <label class="form-label text-xs fw-bold text-uppercase text-secondary" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                        Description / Subtitle <span class="fw-normal text-muted text-capitalize">(Optional)</span>
                    </label>
                    <input type="text" name="description" id="settingDescription" class="form-control form-control-sm" placeholder="Optional description / subtitle" style="border-radius: 6px; border-color: #DCD5C9;">
                </div>
            </div>

            <div class="modal-footer border-0 pt-0 pb-3 px-4 d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 6px;">Cancel</button>
                <button type="submit" class="btn btn-sm px-3 fw-bold" style="border-radius: 6px; background: #A9812E; color: #fff; border: none;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleNewVicarDrawer() {
    var drawer = document.getElementById('newVicarDrawer');
    if (!drawer) return;
    drawer.style.display = (drawer.style.display === 'none' || drawer.style.display === '') ? 'block' : 'none';
    if (drawer.style.display === 'block') {
        var inp = drawer.querySelector('input[name="occupant_name"]');
        if (inp) inp.focus();
    }
}

function openPositionSettings(data) {
    var posId = data ? (data.id || 0) : 0;
    var title = data ? (data.title || '') : '';
    var occupant = data ? (data.occupant || '') : '';
    var phone = data ? (data.phone || '') : '';
    var email = data ? (data.email || '') : '';
    var desc = data ? (data.desc || '') : '';

    document.getElementById('settingPosId').value = posId;
    document.getElementById('settingPositionTitle').value = title;
    document.getElementById('settingOccupantName').value = occupant;
    document.getElementById('settingPhone').value = phone;
    document.getElementById('settingEmail').value = email;
    document.getElementById('settingDescription').value = desc;
    document.getElementById('settingModalTitle').textContent = posId ? (title + ' Settings') : 'Add Ministry Role';

    var modalEl = document.getElementById('positionSettingsModal');
    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    modal.show();
}

// Backwards compatibility alias
function openMinistryModal(posId, title, occupant) {
    openPositionSettings({ id: posId, title: title, occupant: occupant });
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
