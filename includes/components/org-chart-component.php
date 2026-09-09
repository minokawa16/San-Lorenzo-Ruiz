<?php
/**
 * Parish Organizational Chart Component
 *
 * Reusable, dynamic hierarchical organizational chart component.
 * Renders nested <ul>/<li> structures with pure CSS flowchart connector lines,
 * brass rank seals, compact 220px cards, inline direct editing, and responsive
 * collapse below 900px.
 *
 * Visual Palette:
 * - Text: Deep navy (#16233A)
 * - Page Background: Warm ivory (#FAF8F3)
 * - Cards: Crisp white (#FFFFFF), 1px hairline border, 10px radius
 * - Connectors & Seals: Brass/Gold (#A9812E)
 * - Active Status: Sage green (#3F7D58)
 * - Vacant Status: Muted grey (#64748B)
 */

if (!function_exists('renderParishOrgChartStyles')) {
    /**
     * Output the component's embedded CSS styles (guarded to render once).
     */
    function renderParishOrgChartStyles(): void
    {
        static $stylesRendered = false;
        if ($stylesRendered) {
            return;
        }
        $stylesRendered = true;
        ?>
        <style id="parish-org-chart-css">
        @import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap');

        :root {
            --org-bg-page: #FAF8F3;
            --org-text-navy: #16233A;
            --org-brass: #A9812E;
            --org-brass-light: rgba(169, 129, 46, 0.12);
            --org-brass-glow: rgba(169, 129, 46, 0.18);
            --org-card-bg: #FFFFFF;
            --org-card-border: #E5E0D8;
            --org-card-radius: 10px;
            --org-card-width: 220px;
            --org-line-width: 1.5px;
            --org-active-pill-color: #3F7D58;
            --org-active-pill-bg: #EDF5F0;
            --org-active-pill-border: #C6DEC9;
            --org-vacant-pill-color: #64748B;
            --org-vacant-pill-bg: #F1F5F9;
            --org-vacant-pill-border: #E2E8F0;
            --org-font-serif: 'Fraunces', 'Playfair Display', Georgia, serif;
            --org-font-sans: 'Inter', system-ui, -apple-system, sans-serif;
        }

        /* --- Tree Chart Flowchart Canvas --- */
        .parish-org-flowchart {
            width: 100%;
            margin: 0 auto;
            padding: 1.5rem 0.5rem;
            overflow-x: auto;
            overflow-y: visible;
            box-sizing: border-box;
            display: flex;
            justify-content: center;
        }

        .parish-org-chart-wrap {
            display: inline-block;
            margin: 0 auto;
            text-align: center;
        }

        /* Base UL / LI for pure CSS family-tree flowchart */
        .org-tree-root,
        .org-tree-root ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            position: relative;
        }

        .org-tree-item {
            list-style: none;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0 16px;
            box-sizing: border-box;
        }

        /* Horizontal branches of children */
        .org-children-tier {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding-top: 24px;
            margin: 0;
            position: relative;
        }

        /* Vertical stem drop from parent card down to child rail */
        .org-stem-connector {
            position: relative;
            width: 100%;
            height: 38px;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 2;
        }

        .org-stem-connector::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 50%;
            width: 0;
            border-left: var(--org-line-width) solid var(--org-brass);
            transform: translateX(-0.75px);
            z-index: 1;
        }

        /* Small circular rank seal badge on connector line */
        .org-rank-seal {
            position: relative;
            z-index: 3;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--org-brass);
            color: #FFFFFF;
            font-family: var(--org-font-sans);
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--org-bg-page);
            box-shadow: 0 0 0 1px var(--org-brass);
            line-height: 1;
            user-select: none;
            cursor: default;
        }

        /* Horizontal rail above child cards */
        .org-children-tier > .org-tree-item {
            position: relative;
            padding: 24px 14px 0 14px;
        }

        .org-children-tier > .org-tree-item::before,
        .org-children-tier > .org-tree-item::after {
            content: '';
            position: absolute;
            top: 0;
            width: 50%;
            height: 24px;
            border-top: var(--org-line-width) solid var(--org-brass);
            box-sizing: border-box;
        }

        .org-children-tier > .org-tree-item::before {
            left: 0;
        }

        .org-children-tier > .org-tree-item::after {
            right: 0;
            border-left: var(--org-line-width) solid var(--org-brass);
        }

        /* First child: no rail to the left, rounded top-left corner into vertical drop */
        .org-children-tier > .org-tree-item:first-child::before {
            display: none;
        }
        .org-children-tier > .org-tree-item:first-child::after {
            left: 50%;
            right: 0;
            width: 50%;
            border-top-left-radius: 8px;
            border-left: var(--org-line-width) solid var(--org-brass);
        }

        /* Last child: no rail to the right, rounded top-right corner into vertical drop */
        .org-children-tier > .org-tree-item:last-child::after {
            display: none;
        }
        .org-children-tier > .org-tree-item:last-child::before {
            right: 50%;
            left: 0;
            width: 50%;
            border-top-right-radius: 8px;
            border-right: var(--org-line-width) solid var(--org-brass);
        }

        /* Single child: straight vertical drop, no rail */
        .org-children-tier > .org-tree-item:only-child::before {
            display: none;
        }
        .org-children-tier > .org-tree-item:only-child::after {
            left: 50%;
            right: auto;
            width: 0;
            border-top: none;
            border-left: var(--org-line-width) solid var(--org-brass);
            border-radius: 0;
        }

        /* --- THE ORG CARD --- */
        .org-card {
            width: var(--org-card-width);
            min-width: var(--org-card-width);
            max-width: var(--org-card-width);
            background: var(--org-card-bg);
            border: 1px solid var(--org-card-border);
            border-radius: var(--org-card-radius);
            padding: 14px 14px 12px 14px;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 162px;
            box-shadow: none; /* No drop shadows as per spec */
            box-sizing: border-box;
            text-align: left;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        /* Hover: soft brass glow, no layout shift */
        .org-card:hover {
            border-color: var(--org-brass);
            box-shadow: 0 0 0 1px var(--org-brass), 0 4px 14px var(--org-brass-glow);
        }

        /* Editing State: 3px brass top border (padding compensated to prevent layout shift) */
        .org-card.is-editing {
            border-color: var(--org-brass);
            border-top: 3px solid var(--org-brass) !important;
            padding-top: 12px;
            box-shadow: 0 0 0 1px var(--org-brass), 0 4px 16px var(--org-brass-glow);
        }

        /* Vacant Cards: dashed border, holds tree position */
        .org-card.is-vacant {
            border-style: dashed;
            border-color: #CBD5E1;
            background: #FCFBF9;
        }

        .org-card.is-vacant:hover {
            border-color: var(--org-brass);
            border-style: dashed;
        }

        /* Card Header */
        .org-card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.4rem;
            margin-bottom: 0.65rem;
        }

        .org-card-role {
            font-family: var(--org-font-sans);
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6E7A8A;
            line-height: 1.25;
            flex-grow: 1;
        }

        .org-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.68rem;
            font-weight: 600;
            padding: 0.15rem 0.45rem;
            border-radius: 4px;
            white-space: nowrap;
            line-height: 1;
            flex-shrink: 0;
        }

        .org-status-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            display: inline-block;
        }

        .pill-active {
            background: var(--org-active-pill-bg);
            color: var(--org-active-pill-color);
            border: 1px solid var(--org-active-pill-border);
        }
        .pill-active .org-status-dot {
            background: var(--org-active-pill-color);
        }

        .pill-vacant {
            background: var(--org-vacant-pill-bg);
            color: var(--org-vacant-pill-color);
            border: 1px solid var(--org-vacant-pill-border);
        }
        .pill-vacant .org-status-dot {
            background: var(--org-vacant-pill-color);
        }

        /* Card Body */
        .org-card-body {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            margin-bottom: 0.65rem;
        }

        .org-card-name {
            font-family: var(--org-font-serif);
            font-size: 0.98rem;
            font-weight: 700;
            color: var(--org-text-navy);
            line-height: 1.3;
            margin-bottom: 0.2rem;
            word-break: break-word;
        }

        .org-card-name.is-unassigned {
            font-style: italic;
            color: #94A3B8;
            font-weight: 500;
        }

        .org-card-desc {
            font-size: 0.76rem;
            color: #5A6779;
            line-height: 1.35;
        }

        /* Inline Direct Edit Form */
        .org-card-inline-form {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            width: 100%;
        }

        .org-inline-input {
            width: 100%;
            padding: 0.38rem 0.55rem;
            font-size: 0.82rem;
            font-family: var(--org-font-sans);
            color: var(--org-text-navy);
            background: #FFFFFF;
            border: 1px solid var(--org-brass);
            border-radius: 5px;
            outline: none;
            box-shadow: 0 0 0 2px rgba(169, 129, 46, 0.15);
            box-sizing: border-box;
        }

        .org-inline-actions {
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .btn-org-save {
            background: var(--org-text-navy);
            color: #FFFFFF;
            border: none;
            border-radius: 4px;
            font-size: 0.74rem;
            font-weight: 600;
            padding: 0.3rem 0.65rem;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .btn-org-save:hover {
            background: #243552;
        }

        .btn-org-cancel {
            background: transparent;
            color: #64748B;
            border: 1px solid #CBD5E1;
            border-radius: 4px;
            font-size: 0.74rem;
            font-weight: 500;
            padding: 0.3rem 0.55rem;
            cursor: pointer;
        }

        .btn-org-cancel:hover {
            background: #F1F5F9;
            color: var(--org-text-navy);
        }

        /* Card Footer */
        .org-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #F1ECE4;
            padding-top: 0.5rem;
            gap: 0.35rem;
        }

        .btn-org-edit,
        .btn-org-assign {
            background: #FAF8F5;
            border: 1px solid #DCD5C9;
            border-radius: 5px;
            color: var(--org-text-navy);
            font-family: var(--org-font-sans);
            font-size: 0.74rem;
            font-weight: 600;
            padding: 0.25rem 0.55rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.15s ease;
        }

        .btn-org-edit:hover,
        .btn-org-assign:hover {
            background: #FFFFFF;
            border-color: var(--org-brass);
            color: var(--org-brass);
        }

        .btn-org-assign {
            color: #2F6144;
            background: #F2F7F4;
            border-color: #C6DEC9;
        }

        .btn-org-assign:hover {
            background: #E8F2EC;
            border-color: var(--org-active-pill-color);
            color: var(--org-active-pill-color);
        }

        .org-footer-secondary {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-org-icon {
            background: transparent;
            border: none;
            color: #94A3B8;
            padding: 0.2rem 0.35rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-org-icon:hover {
            background: #F1F5F9;
            color: var(--org-text-navy);
        }

        .btn-icon-vacate:hover,
        .btn-icon-archive:hover {
            background: #FEF2F2;
            color: #DC2626;
        }

        .btn-icon-gear:hover {
            color: var(--org-brass);
        }

        /* --- RESPONSIVE COLLAPSE UNDER 900PX --- */
        @media (max-width: 900px) {
            .parish-org-flowchart {
                padding: 0.5rem 0;
                display: block;
            }

            .parish-org-chart-wrap {
                display: block;
                width: 100%;
                text-align: left;
            }

            .org-tree-root,
            .org-tree-root ul,
            .org-children-tier {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                padding: 0;
                margin: 0;
                width: 100%;
            }

            /* Left-indented one step per tier with vertical continuous line */
            .org-children-tier {
                padding-left: 24px;
                margin-left: 18px;
                border-left: var(--org-line-width) solid var(--org-brass);
                position: relative;
            }

            .org-tree-item {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                padding: 10px 0;
                width: 100%;
            }

            /* Remove horizontal branching rails */
            .org-children-tier > .org-tree-item::before,
            .org-children-tier > .org-tree-item::after {
                display: none !important;
            }

            /* Horizontal branch tick from vertical line to card */
            .org-children-tier > .org-tree-item {
                position: relative;
            }

            .org-children-tier > .org-tree-item::before {
                display: block !important;
                content: '';
                position: absolute;
                top: 32px;
                left: -24px;
                width: 24px;
                height: 0;
                border-top: var(--org-line-width) solid var(--org-brass) !important;
                border-left: none !important;
                border-right: none !important;
                border-radius: 0 !important;
            }

            /* Vertical stem connector on mobile */
            .org-stem-connector {
                height: 24px;
                width: auto;
                margin-left: 18px;
                align-self: flex-start;
            }

            .org-stem-connector::before {
                left: 0;
                border-left: var(--org-line-width) solid var(--org-brass);
            }

            .org-rank-seal {
                margin-left: -12px;
            }

            /* Flexible card on mobile */
            .org-card {
                width: 100%;
                max-width: 320px;
                min-width: 0;
            }
        }
        </style>
        <?php
    }
}

if (!function_exists('renderParishOrgChartScripts')) {
    /**
     * Output the component's embedded JavaScript (guarded to render once).
     */
    function renderParishOrgChartScripts(): void
    {
        static $scriptsRendered = false;
        if ($scriptsRendered) {
            return;
        }
        $scriptsRendered = true;
        ?>
        <script id="parish-org-chart-js">
        function toggleCardEdit(posId) {
            var card = document.getElementById('card-pos-' + posId);
            var display = document.getElementById('display-pos-' + posId);
            var form = document.getElementById('form-pos-' + posId);
            if (!display || !form) return;

            if (form.style.display === 'none' || form.style.display === '') {
                display.style.display = 'none';
                form.style.display = 'flex';
                if (card) card.classList.add('is-editing');
                var input = document.getElementById('input-pos-' + posId) || form.querySelector('input[name="occupant_name"]');
                if (input) {
                    input.focus();
                    input.select();
                }
            } else {
                form.style.display = 'none';
                display.style.display = 'block';
                if (card) card.classList.remove('is-editing');
            }
        }
        </script>
        <?php
    }
}

if (!function_exists('renderParishOrgChart')) {
    /**
     * Entry point to render the entire organizational tree.
     *
     * @param array $rootNode Hierarchical tree data starting from Root.
     * @param array $options Configuration options (e.g. editable, csrf_token).
     */
    function renderParishOrgChart(array $rootNode, array $options = []): void
    {
        renderParishOrgChartStyles();
        ?>
        <div class="parish-org-flowchart">
            <div class="parish-org-chart-wrap" id="parishOrgChart">
                <ul class="org-tree-root">
                    <?php renderOrgNode($rootNode, $options); ?>
                </ul>
            </div>
        </div>
        <?php
        renderParishOrgChartScripts();
    }
}

if (!function_exists('renderOrgNode')) {
    /**
     * Recursively render a single tree node (card + connector + children).
     *
     * @param array $node Node data array.
     * @param array $options Configuration options.
     */
    function renderOrgNode(array $node, array $options = []): void
    {
        $children = $node['children'] ?? [];
        $hasChildren = !empty($children);
        $rank = (int)($node['rank'] ?? 1);
        $nextRank = $hasChildren ? ((int)($children[0]['rank'] ?? ($rank + 1))) : ($rank + 1);
        ?>
        <li class="org-tree-item" data-rank="<?php echo $rank; ?>" data-node-id="<?php echo (int)($node['id'] ?? 0); ?>">
            
            <?php renderOrgCard($node, $options); ?>

            <?php if ($hasChildren): ?>
                <!-- Vertical Stem Drop with Rank Seal Badge -->
                <div class="org-stem-connector">
                    <span class="org-rank-seal" title="Tier <?php echo $nextRank; ?> Hierarchy Rank">
                        <?php echo $nextRank; ?>
                    </span>
                </div>

                <!-- Child Subtree Branches -->
                <ul class="org-children-tier" data-tier-rank="<?php echo $nextRank; ?>">
                    <?php foreach ($children as $child): ?>
                        <?php renderOrgNode($child, $options); ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        </li>
        <?php
    }
}

if (!function_exists('renderOrgCard')) {
    /**
     * Render the compact 220px card for an organizational seat.
     *
     * @param array $node Node data array.
     * @param array $options Configuration options.
     */
    function renderOrgCard(array $node, array $options = []): void
    {
        $id = (int)($node['id'] ?? 0);
        $role = trim((string)($node['role'] ?? ''));
        $name = trim((string)($node['name'] ?? ''));
        $desc = trim((string)($node['description'] ?? ''));
        $isVacant = !empty($node['is_vacant']) || empty($name);
        $status = $isVacant ? 'Vacant' : ($node['status'] ?? 'Active');
        $rank = (int)($node['rank'] ?? 1);
        $isSystemRole = !empty($node['is_system_role']);
        $canVacate = !empty($node['can_vacate']) && !$isVacant;
        $canRemove = !empty($node['can_remove']);
        $isCustomMinistry = !empty($node['is_custom_ministry']);
        $editable = $options['editable'] ?? true;
        $csrfToken = $options['csrf_token'] ?? '';
        ?>
        <div class="org-card <?php echo $isVacant ? 'is-vacant' : 'is-active'; ?>" 
             id="card-pos-<?php echo $id; ?>" 
             data-pos-id="<?php echo $id; ?>"
             data-role="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>">
            
            <!-- Card Header: Role Label & Status Pill -->
            <div class="org-card-head">
                <div class="org-card-role" title="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <span class="org-status-pill <?php echo $isVacant ? 'pill-vacant' : 'pill-active'; ?>">
                    <span class="org-status-dot"></span>
                    <?php echo $isVacant ? 'Vacant' : 'Active'; ?>
                </span>
            </div>

            <!-- Card Body: Occupant Name & Description -->
            <div class="org-card-body">
                <!-- Display Mode -->
                <div class="org-card-display" id="display-pos-<?php echo $id; ?>">
                    <div class="org-card-name <?php echo $isVacant ? 'is-unassigned' : ''; ?>">
                        <?php echo $isVacant ? 'Unassigned' : htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php if (!empty($desc)): ?>
                        <div class="org-card-desc"><?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>

                <!-- Inline Edit Form (Hidden by default, shown when editing) -->
                <?php if ($editable): ?>
                    <form method="POST" 
                          class="org-card-inline-form" 
                          id="form-pos-<?php echo $id; ?>" 
                          style="display: none;">
                        <?php if (!empty($csrfToken)): ?>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php elseif (function_exists('csrfInput')): ?>
                            <?php echo csrfInput(); ?>
                        <?php endif; ?>
                        <input type="hidden" name="action" value="set_occupant_direct">
                        <input type="hidden" name="position_id" value="<?php echo $id; ?>">
                        <input type="text" 
                               name="occupant_name" 
                               id="input-pos-<?php echo $id; ?>"
                               value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" 
                               class="org-inline-input" 
                               placeholder="Enter full name" 
                               required 
                               autocomplete="off">
                        <div class="org-inline-actions">
                            <button type="submit" class="btn-org-save">
                                <i class="fas fa-check"></i> Save
                            </button>
                            <button type="button" class="btn-org-cancel" onclick="toggleCardEdit(<?php echo $id; ?>)">
                                Cancel
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Card Footer: Inline Edit / Assign & Secondary Actions -->
            <div class="org-card-footer">
                <?php if ($editable): ?>
                    <div class="org-footer-primary">
                        <?php if ($isVacant): ?>
                            <button type="button" class="btn-org-assign" onclick="toggleCardEdit(<?php echo $id; ?>)">
                                <i class="fas fa-user-plus"></i> Assign
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn-org-edit" onclick="toggleCardEdit(<?php echo $id; ?>)">
                                <i class="fas fa-pen"></i> Edit Name
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Secondary Pinned Utilities (Vacate, Settings, Delete) -->
                    <div class="org-footer-secondary">
                        <?php if ($canVacate): ?>
                            <form method="POST" class="d-inline m-0" onsubmit="return confirm('Clear and vacate position for <?php echo htmlspecialchars(addslashes($role), ENT_QUOTES, 'UTF-8'); ?>?');">
                                <?php if (!empty($csrfToken)): ?>
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php elseif (function_exists('csrfInput')): ?>
                                    <?php echo csrfInput(); ?>
                                <?php endif; ?>
                                <input type="hidden" name="action" value="vacate_position">
                                <input type="hidden" name="position_id" value="<?php echo $id; ?>">
                                <button type="submit" class="btn-org-icon btn-icon-vacate" title="Vacate Position">
                                    <i class="fas fa-user-xmark"></i>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($isCustomMinistry): ?>
                            <button type="button" 
                                    class="btn-org-icon btn-icon-gear" 
                                    onclick="openMinistryModal(<?php echo $id; ?>, '<?php echo htmlspecialchars(addslashes($role), ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars(addslashes($name), ENT_QUOTES, 'UTF-8'); ?>')" 
                                    title="Ministry Settings">
                                <i class="fas fa-gear"></i>
                            </button>
                            <form method="POST" class="d-inline m-0" onsubmit="return confirm('Archive custom ministry role: <?php echo htmlspecialchars(addslashes($role), ENT_QUOTES, 'UTF-8'); ?>?');">
                                <?php if (!empty($csrfToken)): ?>
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php elseif (function_exists('csrfInput')): ?>
                                    <?php echo csrfInput(); ?>
                                <?php endif; ?>
                                <input type="hidden" name="action" value="archive_ministry">
                                <input type="hidden" name="position_id" value="<?php echo $id; ?>">
                                <button type="submit" class="btn-org-icon btn-icon-archive" title="Archive Ministry Role">
                                    <i class="fas fa-trash-can"></i>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canRemove): ?>
                            <form method="POST" class="d-inline m-0" onsubmit="return confirm('Remove this additional Assistant Priest slot?');">
                                <?php if (!empty($csrfToken)): ?>
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php elseif (function_exists('csrfInput')): ?>
                                    <?php echo csrfInput(); ?>
                                <?php endif; ?>
                                <input type="hidden" name="action" value="remove_assistant_priest">
                                <input type="hidden" name="position_id" value="<?php echo $id; ?>">
                                <button type="submit" class="btn-org-icon btn-icon-archive" title="Remove Assistant Priest Slot">
                                    <i class="fas fa-trash-can"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <?php
    }
}
