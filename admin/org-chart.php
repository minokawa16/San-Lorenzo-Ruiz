<?php
/**
 * Parish Organizational Chart
 *
 * A visual, interactive org chart with Catholic parish aesthetic.
 * All names, photos, positions, and sub-items are defined in the
 * $org_nodes array below -- update that array to reflect personnel changes.
 *
 * Hierarchy:
 *   Level 1 -- Pastor / Parish Priest
 *   Level 2 -- Pastoral Council | Finance Council | Admin Staff
 *   Level 3 -- Ministry Leaders (under Pastoral Council)
 *              Communications Team (under Admin Staff)
 *   Level 4 -- Liturgical Ministries | Faith Formation/CCD | Parish Outreach
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
requireAdmin();
requirePermission('users.view');

$page_title = 'Parish Org Chart';

// =========================================================================
//  ORG CHART DATA
//  -------------------------------------------------------------------------
//  To update a person: edit 'name', 'sub_title', 'photo', or 'sub_items'.
//  To upload a photo:  set 'photo' to BASE_URL . 'uploads/staff/filename.jpg'
//  Hierarchy is fully defined by the 'parent' and 'children' keys.
// =========================================================================
$org_nodes = [

    // == LEVEL 1 ===========================================================
    [
        'id'        => 'pastor',
        'level'     => 1,
        'title'     => 'Pastor / Parish Priest',
        'name'      => 'Rev. Fr. Edward Tembo',
        'sub_title' => 'Canonical Head & Administrator',
        'photo'     => null,
        'icon'      => 'fa-cross',
        'sub_items' => [
            'Canonical Head of Parish',
            'Parish Administrator',
            'Sacramental Ministry',
        ],
        'parent'    => null,
        'children'  => ['pastoral-council', 'finance-council', 'admin-staff'],
    ],

    // == LEVEL 2 ===========================================================
    [
        'id'        => 'pastoral-council',
        'level'     => 2,
        'title'     => 'Pastoral Council',
        'name'      => 'Advisory Body',
        'sub_title' => 'Parish Governance & Planning',
        'photo'     => null,
        'icon'      => 'fa-people-group',
        'sub_items' => [
            'Parish Advisory',
            'Strategic Planning',
            'Community Relations',
        ],
        'parent'    => 'pastor',
        'children'  => ['ministry-leaders'],
    ],
    [
        'id'        => 'finance-council',
        'level'     => 2,
        'title'     => 'Finance Council',
        'name'      => 'Budget & Finance Committee',
        'sub_title' => 'Financial Stewardship',
        'photo'     => null,
        'icon'      => 'fa-coins',
        'sub_items' => [
            'Parish Budget',
            'Financial Reporting',
            'Resource Stewardship',
        ],
        'parent'    => 'pastor',
        'children'  => [],
    ],
    [
        'id'        => 'admin-staff',
        'level'     => 2,
        'title'     => 'Admin Staff',
        'name'      => 'Administrative Office',
        'sub_title' => 'Parish Operations',
        'photo'     => null,
        'icon'      => 'fa-building',
        'sub_items' => [
            'Secretary',
            'Bookkeeper',
            'Maintenance Team',
        ],
        'parent'    => 'pastor',
        'children'  => ['communications-team'],
    ],

    // == LEVEL 3 ===========================================================
    [
        'id'        => 'ministry-leaders',
        'level'     => 3,
        'title'     => 'Ministry Leaders',
        'name'      => 'Ministry Coordination Team',
        'sub_title' => 'Under Pastoral Council',
        'photo'     => null,
        'icon'      => 'fa-hands-praying',
        'sub_items' => [
            'Liturgical',
            'Education',
            'Outreach',
        ],
        'parent'    => 'pastoral-council',
        'children'  => ['liturgical-ministries', 'faith-formation', 'parish-outreach'],
    ],
    [
        'id'        => 'communications-team',
        'level'     => 3,
        'title'     => 'Communications Team',
        'name'      => 'Parish Communications',
        'sub_title' => 'Under Admin Staff',
        'photo'     => null,
        'icon'      => 'fa-bullhorn',
        'sub_items' => [
            'Website Management',
            'Parish Bulletin',
        ],
        'parent'    => 'admin-staff',
        'children'  => [],
    ],

    // == LEVEL 4 ===========================================================
    [
        'id'        => 'liturgical-ministries',
        'level'     => 4,
        'title'     => 'Liturgical Ministries',
        'name'      => 'Worship & Liturgy',
        'sub_title' => 'Under Ministry Leaders',
        'photo'     => null,
        'icon'      => 'fa-book-open',
        'sub_items' => [
            'Lectors',
            'EMHCs',
            'Altar Servers',
            'Ushers',
        ],
        'parent'    => 'ministry-leaders',
        'children'  => [],
    ],
    [
        'id'        => 'faith-formation',
        'level'     => 4,
        'title'     => 'Faith Formation / CCD',
        'name'      => 'Religious Education',
        'sub_title' => 'Under Ministry Leaders',
        'photo'     => null,
        'icon'      => 'fa-graduation-cap',
        'sub_items' => [
            'Catechists',
            'First Communion / Confirmation',
            'Youth Ministry (YFC)',
            'RCIA Program',
        ],
        'parent'    => 'ministry-leaders',
        'children'  => [],
    ],
    [
        'id'        => 'parish-outreach',
        'level'     => 4,
        'title'     => 'Parish Outreach & Events',
        'name'      => 'Community Ministry',
        'sub_title' => 'Under Ministry Leaders',
        'photo'     => null,
        'icon'      => 'fa-hand-holding-heart',
        'sub_items' => [
            'Volunteers',
            'Fundraisers',
            'Community Programs',
            'Feast Days / Fiestas',
        ],
        'parent'    => 'ministry-leaders',
        'children'  => [],
    ],
];

// Build lookup and level groups
$nodes_by_id    = [];
$nodes_by_level = [];
foreach ($org_nodes as $node) {
    $nodes_by_id[$node['id']]         = $node;
    $nodes_by_level[$node['level']][] = $node;
}
ksort($nodes_by_level);

// Parent map as JSON for JS connector drawing
$parent_map = [];
foreach ($org_nodes as $node) {
    if ($node['parent'] !== null) {
        $parent_map[$node['id']] = $node['parent'];
    }
}
$parent_map_json = json_encode($parent_map, JSON_HEX_TAG | JSON_HEX_APOS);

include __DIR__ . '/../templates/header.php';
?>
<style>
:root {
    --maroon:      #800000;
    --maroon-dark: #5c0000;
    --cream:       #fdf8f0;
    --cream-dark:  #f5ece0;
    --gold:        #c8a56b;
    --gold-light:  #e3cfa0;
    --tx-primary:  #1a0606;
    --tx-muted:    #6b534a;
    --bd:          #d4bfa8;
    --shadow:      rgba(128,0,0,.10);
}
.orgchart-page { background: var(--cream); min-height: 100vh; padding-bottom: 4rem; }

/* Banner */
.orgchart-banner {
    background: linear-gradient(135deg, var(--maroon-dark) 0%, var(--maroon) 50%, var(--maroon-dark) 100%);
    padding: 2.25rem 2rem 2rem; text-align: center; position: relative; overflow: hidden;
}
.orgchart-banner::before {
    content:''; position:absolute; inset:0;
    background: radial-gradient(circle at 20% 50%, rgba(200,165,107,.12) 0%, transparent 60%),
                radial-gradient(circle at 80% 50%, rgba(200,165,107,.12) 0%, transparent 60%);
    pointer-events:none;
}
.orgchart-banner-cross { font-size:2.25rem; color:var(--gold); display:block; margin-bottom:.5rem; text-shadow:0 2px 8px rgba(0,0,0,.3); }
.orgchart-banner-title {
    font-family:'Playfair Display','Georgia',serif;
    font-size:clamp(1.4rem,4vw,2.25rem); font-weight:700; color:#fff;
    letter-spacing:.12em; text-transform:uppercase; margin:0 0 .4rem;
    text-shadow:0 2px 8px rgba(0,0,0,.25);
}
.orgchart-banner-subtitle { font-size:.92rem; color:var(--gold-light); letter-spacing:.04em; margin:0; opacity:.88; }
.orgchart-banner-divider { width:80px; height:2px; background:linear-gradient(90deg,transparent,var(--gold),transparent); margin:.85rem auto 0; }

/* Top bar */
.orgchart-top-bar {
    display:flex; align-items:center; justify-content:space-between;
    padding:1rem 1.5rem; border-bottom:1px solid var(--cream-dark); background:#fff;
    flex-wrap:wrap; gap:.75rem;
}
.orgchart-breadcrumb { font-size:.82rem; color:var(--tx-muted); display:flex; align-items:center; gap:6px; }
.orgchart-breadcrumb a { color:var(--maroon); text-decoration:none; font-weight:600; }
.orgchart-breadcrumb a:hover { text-decoration:underline; }

/* Buttons */
.btn-oc {
    font-size:.82rem; font-weight:600; padding:.45rem 1rem; border-radius:7px;
    border:1.5px solid var(--maroon); background:transparent; color:var(--maroon);
    cursor:pointer; transition:all .15s ease; display:inline-flex; align-items:center; gap:6px; text-decoration:none;
}
.btn-oc:hover { background:var(--maroon); color:#fff; }
.btn-oc-solid { background:var(--maroon); color:#fff; }
.btn-oc-solid:hover { background:var(--maroon-dark); border-color:var(--maroon-dark); color:#fff; }

/* Scroll outer */
.orgchart-scroll-outer {
    overflow-x:auto; overflow-y:visible; padding:2.5rem 1.5rem 3rem;
    -webkit-overflow-scrolling:touch;
}
.orgchart-scroll-outer::-webkit-scrollbar { height:6px; }
.orgchart-scroll-outer::-webkit-scrollbar-track { background:var(--cream-dark); border-radius:3px; }
.orgchart-scroll-outer::-webkit-scrollbar-thumb { background:var(--gold); border-radius:3px; }

/* Chart container */
.orgchart-container { position:relative; min-width:860px; width:100%; }

#orgConnectorSvg {
    position:absolute; top:0; left:0; width:100%; height:100%;
    pointer-events:none; z-index:1; overflow:visible;
}

/* Level rows */
.orgchart-level { display:flex; justify-content:center; align-items:flex-start; position:relative; z-index:2; }
.orgchart-level + .orgchart-level { margin-top:68px; }
.orgchart-level-2 { gap:28px; }
.orgchart-level-3 { gap:80px; }
.orgchart-level-4 { gap:18px; }

/* Org card */
.org-card {
    background:#fff; border:1.5px solid var(--bd); border-radius:10px;
    overflow:hidden; width:220px; flex-shrink:0;
    box-shadow:0 2px 8px var(--shadow); transition:transform .18s ease,box-shadow .18s ease;
    position:relative; z-index:3;
}
.org-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(128,0,0,.15),0 2px 6px rgba(0,0,0,.06); }
.org-card-level-1 { width:280px; }

/* Card header (maroon rooftop banner) */
.org-card-header {
    background:var(--maroon); padding:9px 12px 11px;
    position:relative; display:flex; align-items:center; gap:7px;
}
.org-card-header::after {
    content:''; position:absolute; bottom:-9px; left:50%; transform:translateX(-50%);
    border-left:10px solid transparent; border-right:10px solid transparent;
    border-top:9px solid var(--maroon); z-index:4;
}
.org-card-header-icon { color:var(--gold); font-size:.78rem; flex-shrink:0; opacity:.9; }
.org-card-header-text { color:#fff; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; line-height:1.25; }

/* Card body */
.org-card-body { display:flex; align-items:flex-start; gap:10px; padding:18px 12px 12px; }

/* Avatar */
.org-avatar {
    width:48px; height:48px; border-radius:50%;
    background:var(--cream-dark); border:2px solid var(--gold);
    flex-shrink:0; display:flex; align-items:center; justify-content:center;
    overflow:hidden; color:var(--maroon); font-size:1.2rem;
}
.org-card-level-1 .org-avatar { width:58px; height:58px; font-size:1.5rem; }
.org-avatar img { width:100%; height:100%; object-fit:cover; border-radius:50%; }

/* Details */
.org-card-details { flex:1; min-width:0; }
.org-card-name { font-size:.82rem; font-weight:700; color:var(--tx-primary); line-height:1.3; margin-bottom:2px; word-break:break-word; }
.org-card-level-1 .org-card-name { font-size:.9rem; }
.org-card-position { font-size:.68rem; color:var(--maroon); font-weight:600; margin-bottom:6px; letter-spacing:.02em; }
.org-sub-list { list-style:none; padding:0; margin:0; }
.org-sub-list li { font-size:.66rem; color:var(--tx-muted); padding:1.5px 0; display:flex; align-items:baseline; gap:4px; line-height:1.35; }
.org-sub-list li::before { content:'•'; color:var(--gold); font-size:.7rem; flex-shrink:0; margin-top:1px; }

/* Tooltip */
.org-card-tooltip {
    display:none; position:absolute; bottom:calc(100% + 10px); left:50%; transform:translateX(-50%);
    background:var(--maroon-dark); color:#fff; font-size:.72rem; padding:5px 10px;
    border-radius:6px; white-space:nowrap; z-index:100; pointer-events:none;
    box-shadow:0 4px 12px rgba(0,0,0,.2);
}
.org-card-tooltip::after {
    content:''; position:absolute; top:100%; left:50%; transform:translateX(-50%);
    border-left:6px solid transparent; border-right:6px solid transparent; border-top:6px solid var(--maroon-dark);
}
.org-card:hover .org-card-tooltip { display:block; }

/* Legend */
.orgchart-legend {
    background:#fff; border:1px solid var(--bd); border-radius:10px;
    padding:1rem 1.5rem; margin:2rem auto 0; max-width:700px;
    display:flex; flex-wrap:wrap; gap:.75rem 2rem; align-items:center; justify-content:center;
}
.legend-item { display:flex; align-items:center; gap:6px; font-size:.78rem; color:var(--tx-muted); }
.legend-dot { width:12px; height:12px; border-radius:50%; background:var(--maroon); border:2px solid var(--gold); flex-shrink:0; }
.legend-line { width:24px; height:2px; background:var(--maroon); border-radius:1px; flex-shrink:0; }

@media print {
    .orgchart-top-bar,.orgchart-legend { display:none!important; }
    .orgchart-banner,.org-card-header { print-color-adjust:exact; -webkit-print-color-adjust:exact; }
    .org-card { break-inside:avoid; }
    .orgchart-scroll-outer { overflow:visible!important; }
}
</style>

<div class="orgchart-page">

    <!-- Top bar -->
    <div class="orgchart-top-bar">
        <div class="orgchart-breadcrumb">
            <a href="<?= BASE_URL ?>admin/dashboard.php"><i class="fas fa-house-chimney me-1"></i> Dashboard</a>
            <span>/</span>
            <span>Parish Org Chart</span>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>admin/organization.php" class="btn-oc">
                <i class="fas fa-pencil"></i> Edit Hierarchy
            </a>
            <button class="btn-oc btn-oc-solid" onclick="window.print()">
                <i class="fas fa-print"></i> Print Chart
            </button>
        </div>
    </div>

    <!-- Banner -->
    <div class="orgchart-banner">
        <i class="fas fa-cross orgchart-banner-cross"></i>
        <h1 class="orgchart-banner-title">Parish Organizational Chart</h1>
        <p class="orgchart-banner-subtitle">
            San Lorenzo Ruiz Mission Station &mdash; Leadership &amp; Ministry Structure
        </p>
        <div class="orgchart-banner-divider"></div>
    </div>

    <!-- Chart -->
    <div class="orgchart-scroll-outer" id="orgScrollOuter">
        <div class="orgchart-container" id="orgContainer">

            <svg id="orgConnectorSvg" aria-hidden="true"></svg>

            <?php foreach ($nodes_by_level as $level => $nodes): ?>
            <div class="orgchart-level orgchart-level-<?= $level ?>" id="orgLevel<?= $level ?>">
                <?php foreach ($nodes as $node):
                    $isL1 = ($node['level'] === 1);
                    $cls  = $isL1 ? 'org-card org-card-level-1' : 'org-card';
                ?>
                <div class="<?= $cls ?>"
                     id="orgNode-<?= e($node['id']) ?>"
                     data-node-id="<?= e($node['id']) ?>"
                     data-parent="<?= e($node['parent'] ?? '') ?>">

                    <div class="org-card-tooltip"><?= e($node['name']) ?></div>

                    <div class="org-card-header">
                        <span class="org-card-header-icon"><i class="fas <?= e($node['icon']) ?>"></i></span>
                        <span class="org-card-header-text"><?= e($node['title']) ?></span>
                    </div>

                    <div class="org-card-body">
                        <div class="org-avatar">
                            <?php if (!empty($node['photo'])): ?>
                                <img src="<?= e($node['photo']) ?>" alt="<?= e($node['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <i class="fas <?= e($node['icon']) ?>"></i>
                            <?php endif; ?>
                        </div>
                        <div class="org-card-details">
                            <div class="org-card-name"><?= e($node['name']) ?></div>
                            <div class="org-card-position"><?= e($node['sub_title']) ?></div>
                            <?php if (!empty($node['sub_items'])): ?>
                            <ul class="org-sub-list">
                                <?php foreach ($node['sub_items'] as $item): ?>
                                <li><?= e($item) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>

                </div><!-- /.org-card -->
                <?php endforeach; ?>
            </div><!-- /.orgchart-level -->
            <?php endforeach; ?>

        </div><!-- /.orgchart-container -->
    </div><!-- /.orgchart-scroll-outer -->

    <!-- Legend -->
    <div class="orgchart-legend mx-3">
        <div class="legend-item"><div class="legend-dot"></div><span>Ministry / Position Node</span></div>
        <div class="legend-item"><div class="legend-line"></div><span>Reporting Relationship</span></div>
        <div class="legend-item">
            <i class="fas fa-cross" style="color:var(--maroon);font-size:.85rem;"></i>
            <span>Pastoral Leadership</span>
        </div>
        <div class="legend-item">
            <a href="<?= BASE_URL ?>admin/organization.php" style="color:var(--maroon);font-weight:600;font-size:.78rem;">
                <i class="fas fa-pencil me-1"></i>Edit Assignments
            </a>
        </div>
    </div>

</div><!-- /.orgchart-page -->

<script>
(function () {
    'use strict';
    var parentMap   = <?= $parent_map_json ?>;
    var STROKE      = '#800000';
    var W           = '2';
    var R           = 8;
    var NS          = 'http://www.w3.org/2000/svg';

    function rect(el, container) {
        var e = el.getBoundingClientRect(), c = container.getBoundingClientRect();
        return {
            top:     e.top    - c.top,
            bottom:  e.bottom - c.top,
            centerX: e.left   - c.left + e.width / 2,
            topY:    e.top    - c.top,
        };
    }

    function elbow(px, py, cx, cy) {
        var mid = py + (cy - py) / 2;
        if (Math.abs(px - cx) < 2) return ['M',px,py,'L',cx,cy].join(' ');
        var goR = cx > px;
        var r1x = goR ? Math.min(px + R, (px+cx)/2) : Math.max(px - R, (px+cx)/2);
        var r2x = goR ? Math.max(cx - R, (px+cx)/2) : Math.min(cx + R, (px+cx)/2);
        return ['M',px,py,'L',px,mid-R,'Q',px,mid,r1x,mid,'L',r2x,mid,'Q',cx,mid,cx,mid+R,'L',cx,cy].join(' ');
    }

    function draw() {
        var con = document.getElementById('orgContainer');
        var svg = document.getElementById('orgConnectorSvg');
        if (!con || !svg) return;
        while (svg.firstChild) svg.removeChild(svg.firstChild);
        var cr = con.getBoundingClientRect();
        svg.setAttribute('width',  cr.width);
        svg.setAttribute('height', cr.height);
        svg.style.width  = cr.width  + 'px';
        svg.style.height = cr.height + 'px';

        Object.keys(parentMap).forEach(function (childId) {
            var pId  = parentMap[childId];
            var cEl  = document.getElementById('orgNode-' + childId);
            var pEl  = document.getElementById('orgNode-' + pId);
            if (!cEl || !pEl) return;
            var c = rect(cEl, con), p = rect(pEl, con);
            var path = document.createElementNS(NS, 'path');
            path.setAttribute('d',              elbow(p.centerX, p.bottom, c.centerX, c.top));
            path.setAttribute('stroke',         STROKE);
            path.setAttribute('stroke-width',   W);
            path.setAttribute('fill',           'none');
            path.setAttribute('stroke-linecap', 'round');
            path.setAttribute('stroke-linejoin','round');
            svg.appendChild(path);
        });
    }

    function init() {
        draw();
        var t;
        window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(draw, 80); });
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(draw);
    }

    document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', init)
        : setTimeout(init, 60);
})();
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
