<?php
/**
 * Parish Organizational Hierarchy Management
 * Redesigned as a true family-tree flowchart organizational chart with
 * thin 1.5px brass connector lines, rank seal badges (1, 2, 3, 4),
 * compact 220px cards, inline rename/assignment, and responsive collapse.
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

// =========================================================================
// BUILD DYNAMIC HIERARCHY TREE DATA FOR COMPONENT
// STRUCTURE:
// Root: Parish Priest (Rank 1)
// Tier 2 (children of Priest): Assistant Priest(s), Parish Secretary
// Tier 3 (children of Parish Secretary): PPC President, PPC Vice President, PPC Secretary, PPC Treasurer
// Tier 4 (children of Council / PPC President): Ministry Coordinators
// =========================================================================

// Root: Parish Priest
$priestOccupant = $tree['tier1']['occupants'][0]['full_name'] ?? '';
$priestVacant = empty($priestOccupant);

$tier2Children = [];

// Assistant Priest(s)
if (!empty($tree['tier2'])) {
    foreach ($tree['tier2'] as $t2) {
        $t2Occ = $t2['occupants'][0]['full_name'] ?? '';
        $t2Vac = empty($t2Occ);
        $tier2Children[] = [
            'id' => (int)$t2['position_id'],
            'role' => $t2['title'],
            'name' => $t2Occ,
            'status' => $t2Vac ? 'Vacant' : 'Active',
            'is_vacant' => $t2Vac,
            'rank' => 2,
            'description' => 'Parochial Vicar',
            'is_system_role' => !empty($t2['is_system_role']),
            'can_vacate' => !$t2Vac,
            'can_remove' => empty($t2['is_system_role']),
            'children' => []
        ];
    }
}

// Parish Secretary
$t3 = $tree['tier3'];
$t3Occ = $t3['occupants'][0]['full_name'] ?? '';
$t3Vac = empty($t3Occ);

// Tier 3: Children of Parish Secretary (PPC Executive Officers)
$ppcChildren = [];
if (!empty($tree['tier4'])) {
    $firstPpc = true;
    foreach ($tree['tier4'] as $p4) {
        $p4Occ = $p4['occupants'][0]['full_name'] ?? '';
        $p4Vac = empty($p4Occ);
        
        // Attach Tier 4 Dynamic Ministries under PPC President
        $ministryChildren = [];
        $isPresident = (stripos($p4['title'], 'President') !== false && stripos($p4['title'], 'Vice') === false) || $firstPpc;
        if ($isPresident && !empty($tree['tier5'])) {
            $firstPpc = false;
            foreach ($tree['tier5'] as $p5) {
                $p5Occ = $p5['occupants'][0]['full_name'] ?? '';
                $p5Vac = empty($p5Occ);
                $ministryChildren[] = [
                    'id' => (int)$p5['position_id'],
                    'role' => $p5['title'],
                    'name' => $p5Occ,
                    'status' => $p5Vac ? 'Vacant' : 'Active',
                    'is_vacant' => $p5Vac,
                    'rank' => 4,
                    'description' => 'Ministry Coordinator',
                    'is_system_role' => false,
                    'is_custom_ministry' => true,
                    'can_vacate' => !$p5Vac,
                    'children' => []
                ];
            }
        }

        $ppcChildren[] = [
            'id' => (int)$p4['position_id'],
            'role' => $p4['title'],
            'name' => $p4Occ,
            'status' => $p4Vac ? 'Vacant' : 'Active',
            'is_vacant' => $p4Vac,
            'rank' => 3,
            'description' => 'Council Officer',
            'is_system_role' => true,
            'can_vacate' => !$p4Vac,
            'children' => $ministryChildren
        ];
    }
}

// Add Parish Secretary to Tier 2 with PPC Officers as its children
$tier2Children[] = [
    'id' => (int)$t3['position_id'],
    'role' => $t3['title'],
    'name' => $t3Occ,
    'status' => $t3Vac ? 'Vacant' : 'Active',
    'is_vacant' => $t3Vac,
    'rank' => 2,
    'description' => 'Chancery & Parish Office Operations',
    'is_system_role' => true,
    'can_vacate' => !$t3Vac,
    'children' => $ppcChildren
];

// Root Node
$orgChartTree = [
    'id' => (int)$tree['tier1']['position_id'],
    'role' => $tree['tier1']['title'],
    'name' => $priestOccupant,
    'status' => $priestVacant ? 'Vacant' : 'Active',
    'is_vacant' => $priestVacant,
    'rank' => 1,
    'description' => 'Canonical Head & Pastor',
    'is_system_role' => true,
    'can_vacate' => !$priestVacant,
    'children' => $tier2Children
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
            <p class="org-admin-subtitle">Family-tree flowchart chart with brass connector lines, rank seals, and inline editing.</p>
        </div>
        <div class="org-admin-metrics">
            <div class="org-metric-pill">
                <span>Ranks:</span> <strong>4 Tiers</strong>
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
            <button type="button" class="btn-org-cta" onclick="openMinistryModal(0, '', '')">
                <i class="fas fa-plus"></i> Add Ministry Role
            </button>
            <a href="<?php echo BASE_URL; ?>users/organization.php" class="btn-org-cta ms-1" target="_blank" title="Public Directory View">
                <i class="fas fa-arrow-up-right-from-square"></i> Public
            </a>
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
    <!-- RENDER DYNAMIC PARISH ORGANIZATIONAL CHART COMPONENT -->
    <!-- ================================================================= -->
    <?php 
    renderParishOrgChart($orgChartTree, [
        'editable' => true,
        'csrf_token' => generateCsrfToken()
    ]); 
    ?>

</div>

<!-- Minimalist Ministry Modal for Custom Apostolates -->
<div class="modal fade" id="ministryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form method="POST" class="modal-content rounded-3 border-0 shadow" style="border-radius: 10px;">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="save_ministry">
            <input type="hidden" name="position_id" id="modalMinistryPosId" value="0">

            <div class="modal-header border-0 pb-0 pt-3 px-3">
                <h6 class="modal-title fw-bold" id="modalMinistryTitle" style="font-family: 'Fraunces', 'Playfair Display', serif; color: #16233A;">Ministry Coordinator Role</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-3 px-3">
                <div class="mb-2.5">
                    <label class="form-label text-xs fw-bold text-uppercase text-secondary" style="letter-spacing: 0.05em;">Ministry Name</label>
                    <input type="text" name="title" id="inputMinistryName" class="org-inline-input w-100" required placeholder="e.g. Ministry of Greeters & Ushers">
                </div>
                <div class="mt-2">
                    <label class="form-label text-xs fw-bold text-uppercase text-secondary" style="letter-spacing: 0.05em;">Coordinator Name (Optional)</label>
                    <input type="text" name="occupant_name" id="inputMinistryCoordinator" class="org-inline-input w-100" placeholder="Enter coordinator name">
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 pb-3 px-3">
                <button type="button" class="btn-org-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn-org-save">Save Role</button>
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
