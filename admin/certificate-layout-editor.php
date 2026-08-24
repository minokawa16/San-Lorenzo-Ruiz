<?php
/**
 * Drag-and-drop certificate layout editor.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
include '../includes/CertificateTemplateManager.php';

requireAdmin();
requirePermission('certificates.manage');
ensureCertificateTemplateSchema($conn);

$current_user_id = intval($_SESSION['user_id'] ?? 0);
$types = certificateTemplateTypes();
$certificate_type = normalizeCertificateTemplateType($_GET['type'] ?? ($_POST['certificate_type'] ?? 'baptism'));
if (!isset($types[$certificate_type])) {
    $certificate_type = 'baptism';
}

function editorRedirect($type, $message, $notice_type = 'success') {
    queueActionNotification($message, $notice_type);
    header('Location: certificate-layout-editor.php?type=' . urlencode($type));
    exit;
}

$layout = getCertificateLayout($conn, $certificate_type);
$settings = $layout['settings'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? 'save_layout';
    $certificate_type = normalizeCertificateTemplateType($_POST['certificate_type'] ?? $certificate_type);
    $layout = getCertificateLayout($conn, $certificate_type);
    $settings = $layout['settings'];

    if ($action === 'upload_asset') {
        $asset_key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_POST['asset_key'] ?? '')));
        if (!isset($settings['images'][$asset_key])) {
            editorRedirect($certificate_type, 'Invalid image slot.', 'error');
        }
        $upload = saveCertificateLayoutAsset($_FILES['asset_file'] ?? null, $certificate_type, $asset_key);
        if (!$upload['ok']) {
            editorRedirect($certificate_type, $upload['error'], 'error');
        }
        $settings['images'][$asset_key] = $upload['path'];
        if (!saveCertificateLayout($conn, $certificate_type, $settings, $current_user_id)) {
            editorRedirect($certificate_type, 'Failed to save layout.', 'error');
        }
        createAuditLog($conn, $current_user_id, 'UPDATE_CERTIFICATE_LAYOUT_IMAGE', 'certificate_layouts', 0, null, ['certificate_type' => $certificate_type, 'asset' => $asset_key]);
        editorRedirect($certificate_type, ucwords(str_replace('_', ' ', $asset_key)) . ' updated successfully.');
    }

    if ($action === 'delete_asset') {
        $asset_key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_POST['asset_key'] ?? '')));
        if (!isset($settings['images'][$asset_key])) {
            editorRedirect($certificate_type, 'Invalid image slot.', 'error');
        }
        $settings['images'][$asset_key] = '';
        if (!saveCertificateLayout($conn, $certificate_type, $settings, $current_user_id)) {
            editorRedirect($certificate_type, 'Failed to save layout.', 'error');
        }
        createAuditLog($conn, $current_user_id, 'DELETE_CERTIFICATE_LAYOUT_IMAGE', 'certificate_layouts', 0, null, ['certificate_type' => $certificate_type, 'asset' => $asset_key]);
        editorRedirect($certificate_type, ucwords(str_replace('_', ' ', $asset_key)) . ' removed successfully.');
    }

    if ($action === 'save_layout') {
        $posted_settings = json_decode($_POST['layout_settings'] ?? '', true);
        if (!is_array($posted_settings)) {
            editorRedirect($certificate_type, 'Failed to save layout.', 'error');
        }
        $posted_settings['images'] = $settings['images'];
        if (!saveCertificateLayout($conn, $certificate_type, $posted_settings, $current_user_id)) {
            editorRedirect($certificate_type, 'Failed to save layout.', 'error');
        }
        createAuditLog($conn, $current_user_id, 'SAVE_CERTIFICATE_LAYOUT', 'certificate_layouts', 0, null, ['certificate_type' => $certificate_type]);
        editorRedirect($certificate_type, 'Layout saved successfully.');
    }
}

$layout = getCertificateLayout($conn, $certificate_type);
$settings = $layout['settings'];
$page_title = 'Edit Certificate Layout';
$notifications = consumeActionNotifications();
$asset_labels = [
    'parish_logo' => 'SLR Logo',
    'diocese_logo' => 'Diocese Logo',
];
$simple_text_fields = [
    'church_title' => 'Church Title',
    'diocese_name' => 'Diocese / Archdiocese',
    'parish_name' => 'Parish Name',
    'parish_address' => 'Parish Address',
    'certificate_title' => 'Certificate Title',
    'certificate_subtitle' => 'Certificate Subtitle',
    'body_text' => 'Certificate Body Text',
    'footer_text' => 'Footer Text',
    'priest_name' => 'Parish Priest Name',
    'priest_position' => 'Parish Priest Position',
    'secretary_name' => 'Parish Secretary Name',
    'secretary_position' => 'Parish Secretary Position',
];
?>
<?php include '../templates/header.php'; ?>

<style>
    .layout-editor-shell { display: grid; grid-template-columns: minmax(310px, 420px) minmax(0, 1fr); gap: 18px; align-items: start; }
    .editor-panel { background: #fff; border: 1px solid rgba(15,23,42,.1); border-radius: 8px; box-shadow: 0 14px 34px rgba(15,23,42,.08); }
    .editor-panel .panel-head { padding: 14px 16px; border-bottom: 1px solid rgba(15,23,42,.1); font-weight: 800; }
    .editor-panel .panel-body { padding: 16px; }
    .editor-preview-wrap { overflow: auto; padding: 18px; background: #e8edf3; border-radius: 8px; }
    .editor-canvas { width: 152.4mm; height: 228.6mm; position: relative; margin: 0 auto; background: #fff; box-shadow: 0 18px 44px rgba(15,23,42,.22); overflow: hidden; font-family: "Times New Roman", serif; color: #151515; }
    .editor-border { position: absolute; inset: 4mm; border: 2px double #111; pointer-events: none; }
    .editor-corner { position: absolute; width: 18mm; height: 18mm; border-color: #111; border-style: solid; pointer-events: none; }
    .editor-corner.tl { left: 8mm; top: 8mm; border-width: 1px 0 0 1px; }
    .editor-corner.tr { right: 8mm; top: 8mm; border-width: 1px 1px 0 0; }
    .editor-corner.bl { left: 8mm; bottom: 8mm; border-width: 0 0 1px 1px; }
    .editor-corner.br { right: 8mm; bottom: 8mm; border-width: 0 1px 1px 0; }
    .layout-item { position: absolute; min-width: 8mm; min-height: 5mm; cursor: move; outline: 1px dashed transparent; display: flex; align-items: center; justify-content: center; text-align: center; overflow: hidden; }
    .layout-item:hover, .layout-item.active { outline-color: #2563eb; background: rgba(37,99,235,.04); }
    .layout-item img { max-width: 100%; max-height: 100%; object-fit: contain; pointer-events: none; }
    .resize-handle { position: absolute; width: 8px; height: 8px; right: 0; bottom: 0; background: #2563eb; cursor: nwse-resize; }
    .tiny-label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: .06em; }
    .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .asset-thumb { width: 44px; height: 44px; object-fit: contain; border: 1px solid #e5e7eb; border-radius: 6px; background: #f8fafc; }
    .layout-editor-actions { position: relative; z-index: 20; pointer-events: auto; }
    .simple-section-title { font-size: .78rem; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 10px; }
    .simple-help { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; color: #475569; font-size: .9rem; margin-bottom: 14px; }
    .advanced-editor { border: 1px solid #e2e8f0; border-radius: 8px; background: #fbfdff; }
    .advanced-editor summary { cursor: pointer; padding: 12px 14px; font-weight: 800; color: #334155; }
    .advanced-editor .advanced-body { padding: 0 14px 14px; }
    .asset-row { border: 1px solid #eef2f7; border-radius: 8px; padding: 8px; background: #fff; }
    .asset-row strong { font-size: .88rem; }
    @media (max-width: 1100px) { .layout-editor-shell { grid-template-columns: 1fr; } }
</style>

<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h1 class="mb-1"><i class="fas fa-pen-ruler"></i> Edit Certificate Layout</h1>
            <p class="text-muted mb-0"><?php echo e(certificateTemplateTypeLabel($certificate_type)); ?></p>
        </div>
        <div class="d-flex gap-2 layout-editor-actions">
            <a class="btn btn-outline-secondary" href="certificate-templates.php"><i class="fas fa-arrow-left"></i> Back</a>
            <button class="btn btn-primary" id="topSaveLayoutBtn" type="button"><i class="fas fa-save"></i> Save Layout</button>
        </div>
    </div>

    <?php foreach ($notifications as $notice): ?>
        <div class="alert alert-<?php echo $notice['type'] === 'error' ? 'danger' : e($notice['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo e($notice['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="layout-editor-shell">
        <div class="editor-panel">
            <div class="panel-head">Simple Layout Editor</div>
            <div class="panel-body">
                <form id="layoutEditorForm" method="POST">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="save_layout">
                    <input type="hidden" name="certificate_type" value="<?php echo e($certificate_type); ?>">
                    <input type="hidden" name="layout_settings" id="layoutSettingsInput">

                    <div class="simple-help">
                        Edit the certificate words here. The preview updates immediately. Use the advanced section only when you need font, border, or position changes.
                    </div>

                    <div class="simple-section-title">Basic Certificate Details</div>
                    <?php foreach ($simple_text_fields as $key => $label): ?>
                        <div class="mb-2">
                            <label class="form-label small"><?php echo e($label); ?></label>
                            <?php if (in_array($key, ['body_text', 'footer_text'], true)): ?>
                                <textarea class="form-control layout-input" rows="3" data-path="static_text.<?php echo e($key); ?>"><?php echo e($settings['static_text'][$key] ?? ''); ?></textarea>
                            <?php else: ?>
                                <input class="form-control layout-input" data-path="static_text.<?php echo e($key); ?>" value="<?php echo e($settings['static_text'][$key] ?? ''); ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="d-grid mt-3 mb-3">
                        <button class="btn btn-primary btn-lg" type="submit"><i class="fas fa-save"></i> Save Layout</button>
                    </div>

                    <details class="advanced-editor">
                        <summary><i class="fas fa-sliders"></i> Advanced styling and positioning</summary>
                        <div class="advanced-body">
                            <ul class="nav nav-tabs" role="tablist">
                                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#textTab" type="button">More Text</button></li>
                                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#styleTab" type="button">Style</button></li>
                                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#positionTab" type="button">Position</button></li>
                            </ul>

                            <div class="tab-content pt-3">
                                <div class="tab-pane fade show active" id="textTab">
                                    <?php foreach ($settings['static_text'] as $key => $value): ?>
                                <div class="mb-2">
                                    <label class="form-label small"><?php echo e(ucwords(str_replace('_', ' ', $key))); ?></label>
                                    <?php if (in_array($key, ['body_text', 'footer_text', 'bible_verse', 'official_remarks'], true)): ?>
                                        <textarea class="form-control layout-input" rows="2" data-path="static_text.<?php echo e($key); ?>"><?php echo e($value); ?></textarea>
                                    <?php else: ?>
                                        <input class="form-control layout-input" data-path="static_text.<?php echo e($key); ?>" value="<?php echo e($value); ?>">
                                    <?php endif; ?>
                                </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="tab-pane fade" id="styleTab">
                            <div class="form-grid-2">
                                <div><label class="form-label small">Font Family</label><select class="form-select layout-input" data-path="typography.font_family"><option>Times New Roman</option><option>Georgia</option><option>Arial</option><option>Calibri</option><option>Garamond</option></select></div>
                                <div><label class="form-label small">Font Size</label><input type="number" step=".1" class="form-control layout-input" data-path="typography.font_size"></div>
                                <div><label class="form-label small">Font Color</label><input type="color" class="form-control form-control-color layout-input" data-path="typography.font_color"></div>
                                <div><label class="form-label small">Font Weight</label><select class="form-select layout-input" data-path="typography.font_weight"><option value="400">Regular</option><option value="700">Bold</option><option value="900">Heavy</option></select></div>
                                <div><label class="form-label small">Alignment</label><select class="form-select layout-input" data-path="typography.text_align"><option>left</option><option>center</option><option>right</option><option>justify</option></select></div>
                                <div><label class="form-label small">Line Height</label><input type="number" step=".05" class="form-control layout-input" data-path="typography.line_height"></div>
                                <div><label class="form-label small">Letter Spacing</label><input type="number" step=".1" class="form-control layout-input" data-path="typography.letter_spacing"></div>
                                <div><label class="form-label small">Border Style</label><select class="form-select layout-input" data-path="border.style"><option>solid</option><option>double</option><option>dashed</option><option>dotted</option></select></div>
                                <div><label class="form-label small">Border Thickness</label><input type="number" class="form-control layout-input" data-path="border.thickness"></div>
                                <div><label class="form-label small">Border Color</label><input type="color" class="form-control form-control-color layout-input" data-path="border.color"></div>
                            </div>
                            <div class="d-flex flex-wrap gap-3 mt-3">
                                <label class="form-check"><input class="form-check-input layout-input" type="checkbox" data-path="typography.bold"> Bold</label>
                                <label class="form-check"><input class="form-check-input layout-input" type="checkbox" data-path="typography.italic"> Italic</label>
                                <label class="form-check"><input class="form-check-input layout-input" type="checkbox" data-path="typography.underline"> Underline</label>
                                <label class="form-check"><input class="form-check-input layout-input" type="checkbox" data-path="border.visible"> Border Visible</label>
                                <label class="form-check"><input class="form-check-input layout-input" type="checkbox" data-path="border.decorative_corners"> Decorative Corners</label>
                            </div>
                                </div>

                                <div class="tab-pane fade" id="positionTab">
                            <label class="form-label small">Selected Element</label>
                            <select class="form-select mb-2" id="selectedElement"></select>
                            <div class="form-grid-2">
                                <div><label class="form-label small">X</label><input type="number" step=".5" class="form-control pos-input" data-pos="x"></div>
                                <div><label class="form-label small">Y</label><input type="number" step=".5" class="form-control pos-input" data-pos="y"></div>
                                <div><label class="form-label small">Width</label><input type="number" step=".5" class="form-control pos-input" data-pos="w"></div>
                                <div><label class="form-label small">Height</label><input type="number" step=".5" class="form-control pos-input" data-pos="h"></div>
                                <div><label class="form-label small">Rotate</label><input type="number" step="1" class="form-control pos-input" data-pos="rotate"></div>
                                <div><label class="form-label small">Opacity</label><input type="number" min="0" max="1" step=".05" class="form-control pos-input" data-pos="opacity"></div>
                            </div>
                                </div>
                            </div>
                        </div>
                    </details>
                </form>

                <hr>
                <div class="simple-section-title">Images</div>
                <?php foreach ($asset_labels as $key => $label): ?>
                    <div class="asset-row d-flex align-items-center gap-2 mb-2">
                        <img class="asset-thumb" src="<?php echo e($settings['images'][$key] ? certificateLayoutAssetUrl($settings['images'][$key]) : '../assets/img/san-lorenzo-logo.png'); ?>" alt="">
                        <div style="min-width: 92px;"><strong><?php echo e($label); ?></strong></div>
                        <form method="POST" enctype="multipart/form-data" class="flex-grow-1 d-flex gap-1">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="upload_asset">
                            <input type="hidden" name="certificate_type" value="<?php echo e($certificate_type); ?>">
                            <input type="hidden" name="asset_key" value="<?php echo e($key); ?>">
                            <input class="form-control form-control-sm" type="file" name="asset_file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" required>
                            <button class="btn btn-sm btn-outline-primary" type="submit" title="Upload selected logo" aria-label="Upload selected <?php echo e($label); ?>"><i class="fas fa-upload"></i></button>
                        </form>
                        <form method="POST">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="delete_asset">
                            <input type="hidden" name="certificate_type" value="<?php echo e($certificate_type); ?>">
                            <input type="hidden" name="asset_key" value="<?php echo e($key); ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Remove current logo" aria-label="Remove current <?php echo e($label); ?>"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="editor-preview-wrap">
            <div class="editor-canvas" id="editorCanvas">
                <div class="editor-border" id="editorBorder"></div>
                <div class="editor-corner tl"></div><div class="editor-corner tr"></div><div class="editor-corner bl"></div><div class="editor-corner br"></div>
                <div class="layout-item" data-element="diocese_logo"><img data-image="diocese_logo"><div class="resize-handle"></div></div>
                <div class="layout-item" data-element="parish_logo"><img data-image="parish_logo"><div class="resize-handle"></div></div>
                <div class="layout-item" data-element="church_info"><div><strong data-preview="church_title"></strong><br><span data-preview="diocese_name"></span><br><span data-preview="parish_name"></span><br><span data-preview="parish_address"></span></div><div class="resize-handle"></div></div>
                <div class="layout-item" data-element="certificate_title"><strong data-preview="certificate_title"></strong><div class="resize-handle"></div></div>
                <div class="layout-item" data-element="body_text"><span data-preview="body_text"></span><div class="resize-handle"></div></div>
                <div class="layout-item" data-element="footer"><span data-preview="footer_text"></span><div class="resize-handle"></div></div>
                <div class="layout-item" data-element="qr_code"><div style="width:100%;height:100%;border:1px solid #111;display:grid;place-items:center;font-size:10px;">QR</div><div class="resize-handle"></div></div>
            </div>
        </div>
    </div>
</div>

<script>
const settings = <?php echo json_encode($settings, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const assetUrls = {};
Object.entries(settings.images).forEach(([key, value]) => {
    assetUrls[key] = value ? '../certificate-layout-asset.php?asset=' + encodeURIComponent(value.replace(/\\/g, '/').split('/').pop()) : '';
});

function getPath(path) {
    return path.split('.').reduce((obj, key) => obj && obj[key], settings);
}
function setPath(path, value) {
    const parts = path.split('.');
    let obj = settings;
    while (parts.length > 1) obj = obj[parts.shift()];
    obj[parts[0]] = value;
}
function applyPreview() {
    document.querySelectorAll('[data-preview]').forEach((el) => {
        el.textContent = settings.static_text[el.dataset.preview] || '';
    });
    document.querySelectorAll('[data-image]').forEach((img) => {
        const src = assetUrls[img.dataset.image] || '../assets/img/san-lorenzo-logo.png';
        img.src = src;
        img.hidden = !assetUrls[img.dataset.image] && !['parish_logo', 'diocese_logo'].includes(img.dataset.image);
    });
    const typo = settings.typography;
    document.getElementById('editorCanvas').style.fontFamily = typo.font_family;
    document.getElementById('editorCanvas').style.fontSize = `${typo.font_size}pt`;
    document.getElementById('editorCanvas').style.color = typo.font_color;
    document.getElementById('editorCanvas').style.fontWeight = typo.bold ? '700' : typo.font_weight;
    document.getElementById('editorCanvas').style.fontStyle = typo.italic ? 'italic' : 'normal';
    document.getElementById('editorCanvas').style.textDecoration = typo.underline ? 'underline' : 'none';
    document.getElementById('editorCanvas').style.textAlign = typo.text_align;
    document.getElementById('editorCanvas').style.letterSpacing = `${typo.letter_spacing}pt`;
    document.getElementById('editorCanvas').style.lineHeight = typo.line_height;

    const border = settings.border;
    const borderEl = document.getElementById('editorBorder');
    borderEl.style.display = border.visible ? '' : 'none';
    borderEl.style.borderStyle = border.style;
    borderEl.style.borderWidth = `${border.thickness}px`;
    borderEl.style.borderColor = border.color;
    document.querySelectorAll('.editor-corner').forEach((el) => {
        el.style.display = border.decorative_corners ? '' : 'none';
        el.style.borderColor = border.color;
    });

    document.querySelectorAll('.layout-item').forEach((item) => {
        const pos = settings.elements[item.dataset.element];
        item.style.left = `${pos.x}mm`;
        item.style.top = `${pos.y}mm`;
        item.style.width = `${pos.w}mm`;
        item.style.height = `${pos.h}mm`;
        item.style.opacity = pos.opacity;
        item.style.transform = `rotate(${pos.rotate}deg)`;
    });
    document.getElementById('layoutSettingsInput').value = JSON.stringify(settings);
}

document.querySelectorAll('.layout-input').forEach((input) => {
    const value = getPath(input.dataset.path);
    if (input.type === 'checkbox') input.checked = !!value;
    else input.value = value;
    input.addEventListener('input', () => {
        let next = input.type === 'checkbox' ? input.checked : input.value;
        if (input.type === 'number') next = parseFloat(next || 0);
        setPath(input.dataset.path, next);
        document.querySelectorAll(`.layout-input[data-path="${input.dataset.path}"]`).forEach((peer) => {
            if (peer === input) return;
            if (peer.type === 'checkbox') {
                peer.checked = !!next;
            } else {
                peer.value = next;
            }
        });
        applyPreview();
    });
});

const selectedElement = document.getElementById('selectedElement');
document.querySelectorAll('.layout-item').forEach((item) => {
    const key = item.dataset.element;
    const option = document.createElement('option');
    option.value = key;
    option.textContent = key.replace(/_/g, ' ');
    selectedElement.appendChild(option);
});
function syncPositionInputs() {
    const pos = settings.elements[selectedElement.value];
    document.querySelectorAll('.pos-input').forEach((input) => input.value = pos[input.dataset.pos]);
    document.querySelectorAll('.layout-item').forEach((item) => item.classList.toggle('active', item.dataset.element === selectedElement.value));
}
selectedElement.addEventListener('change', syncPositionInputs);
document.querySelectorAll('.pos-input').forEach((input) => {
    input.addEventListener('input', () => {
        settings.elements[selectedElement.value][input.dataset.pos] = parseFloat(input.value || 0);
        applyPreview();
    });
});

let drag = null;
document.querySelectorAll('.layout-item').forEach((item) => {
    item.addEventListener('pointerdown', (event) => {
        selectedElement.value = item.dataset.element;
        syncPositionInputs();
        const rect = item.getBoundingClientRect();
        const canvas = document.getElementById('editorCanvas').getBoundingClientRect();
        const resize = event.target.classList.contains('resize-handle');
        drag = { item, resize, startX: event.clientX, startY: event.clientY, canvasW: canvas.width, canvasH: canvas.height, start: {...settings.elements[item.dataset.element]} };
        item.setPointerCapture(event.pointerId);
    });
});
document.addEventListener('pointermove', (event) => {
    if (!drag) return;
    const pos = settings.elements[drag.item.dataset.element];
    const dx = (event.clientX - drag.startX) / drag.canvasW * 152.4;
    const dy = (event.clientY - drag.startY) / drag.canvasH * 228.6;
    if (drag.resize) {
        pos.w = Math.max(4, +(drag.start.w + dx).toFixed(1));
        pos.h = Math.max(4, +(drag.start.h + dy).toFixed(1));
    } else {
        pos.x = +(drag.start.x + dx).toFixed(1);
        pos.y = +(drag.start.y + dy).toFixed(1);
    }
    syncPositionInputs();
    applyPreview();
});
document.addEventListener('pointerup', () => drag = null);

function submitLayoutEditor() {
    const form = document.getElementById('layoutEditorForm');
    document.getElementById('layoutSettingsInput').value = JSON.stringify(settings);
    if (form.requestSubmit) {
        form.requestSubmit();
    } else {
        form.submit();
    }
}
document.getElementById('layoutEditorForm').addEventListener('submit', () => {
    document.getElementById('layoutSettingsInput').value = JSON.stringify(settings);
});
document.getElementById('topSaveLayoutBtn').addEventListener('click', submitLayoutEditor);
selectedElement.value = 'church_info';
applyPreview();
syncPositionInputs();
</script>

<?php include '../templates/footer.php'; ?>
