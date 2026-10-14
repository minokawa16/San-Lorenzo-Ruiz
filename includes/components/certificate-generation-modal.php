<!-- =========================================================================
     CERTIFICATE GENERATION MODAL COMPONENT (GREEN & GOLD THEMED)
     Used across Sacramental Registry Tables: Baptism, Communion, Confirmation, Marriage, Funeral
     ========================================================================= -->
<div id="certGenerationModal" class="cert-modal-backdrop" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="certModalTitle">
    <div class="cert-modal-dialog">
        <div class="cert-modal-content">
            <!-- Modal Header -->
            <div class="cert-modal-header">
                <div class="cert-modal-header-main">
                    <div class="cert-modal-icon-badge">
                        <i class="fas fa-certificate"></i>
                    </div>
                    <div>
                        <h3 id="certModalTitle" class="cert-modal-title">Generate Certificate</h3>
                        <p class="cert-modal-subtitle" id="certModalRegistryBadge">Sacramental Registry Record</p>
                    </div>
                </div>
                <button type="button" class="cert-modal-close" id="certModalCloseBtn" aria-label="Close modal" onclick="closeCertModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="cert-modal-body">
                <!-- Record Summary Card -->
                <div class="cert-record-card">
                    <div class="cert-record-meta-row">
                        <span class="cert-record-ref" id="certModalRefNo"><i class="fas fa-hashtag me-1"></i>Ref: -</span>
                        <span class="cert-record-date" id="certModalDateBadge"><i class="fas fa-calendar-alt me-1"></i>Date: -</span>
                    </div>
                    <div class="cert-record-name-wrap">
                        <span class="cert-record-name-label">Record for</span>
                        <h4 class="cert-record-name" id="certModalRecordName">Recipient Name</h4>
                    </div>
                </div>

                <!-- Form -->
                <form id="certGenerationForm" method="GET" action="generate-certificate.php">
                    <input type="hidden" name="registry" id="certModalRegistryInput" value="">
                    <input type="hidden" name="record_id" id="certModalRecordIdInput" value="">

                    <label class="cert-selection-heading">Select Document to Issue:</label>

                    <div class="cert-choice-group">
                        <!-- Choice 1: Original Certificate (Baptism, Communion, Confirmation ONLY) -->
                        <div class="cert-choice-item" id="certChoiceOriginalWrap">
                            <label class="cert-choice-card" for="certTypeOriginal">
                                <input type="radio" name="type" id="certTypeOriginal" value="original" checked>
                                <span class="cert-custom-radio"></span>
                                <div class="cert-choice-content">
                                    <div class="cert-choice-header">
                                        <span class="cert-choice-title">
                                            <i class="fas fa-award cert-icon-gold"></i> Original Certificate
                                        </span>
                                        <span class="cert-badge-official">Official</span>
                                    </div>
                                    <p class="cert-choice-description">
                                        The official certificate with the parish seal and the priest's signature.
                                    </p>
                                </div>
                            </label>
                        </div>

                        <!-- Choice 2: Certification (All registries) -->
                        <div class="cert-choice-item" id="certChoiceCertificationWrap">
                            <label class="cert-choice-card" for="certTypeCertification">
                                <input type="radio" name="type" id="certTypeCertification" value="certification">
                                <span class="cert-custom-radio"></span>
                                <div class="cert-choice-content">
                                    <div class="cert-choice-header">
                                        <span class="cert-choice-title">
                                            <i class="fas fa-file-contract cert-icon-green"></i> Certification
                                        </span>
                                        <span class="cert-badge-certified">Certified Copy</span>
                                    </div>
                                    <p class="cert-choice-description">
                                        A certified true copy of the record from the parish register.
                                    </p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Notice banner for Marriage & Funeral records -->
                    <div class="cert-modal-callout" id="certModalNotice" style="display: none;">
                        <i class="fas fa-circle-info"></i>
                        <span id="certModalNoticeText">Marriage records are issued as Certification only.</span>
                    </div>

                    <!-- Modal Action Buttons -->
                    <div class="cert-modal-actions">
                        <button type="button" class="btn-cert-cancel" onclick="closeCertModal()">
                            Cancel
                        </button>
                        <button type="submit" class="btn-cert-generate" id="certModalGenerateBtn">
                            <i class="fas fa-stamp me-1.5"></i> Generate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* ── Certificate Modal Backdrop & Dialog ──────────────────────────────── */
.cert-modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.62);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    z-index: 10050;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    box-sizing: border-box;
    opacity: 1;
    animation: certModalFadeIn 0.22s ease-out;
}

@keyframes certModalFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.cert-modal-dialog {
    width: 100%;
    max-width: 500px;
    margin: auto;
    position: relative;
    animation: certModalSlideUp 0.24s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes certModalSlideUp {
    from {
        opacity: 0;
        transform: translateY(18px) scale(0.97);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* ── Modal Content Container ─────────────────────────────────────────── */
.cert-modal-content {
    background: #FFFFFF;
    border: 1px solid #E2D8C3;
    border-radius: 16px;
    box-shadow: 0 20px 45px rgba(21, 34, 21, 0.22), 0 4px 12px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

/* ── Header ──────────────────────────────────────────────────────────── */
.cert-modal-header {
    background: linear-gradient(135deg, #2E3A2D 0%, #1E271D 100%);
    color: #FFFFFF;
    padding: 18px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 2px solid #C89B3C;
}

.cert-modal-header-main {
    display: flex;
    align-items: center;
    gap: 12px;
}

.cert-modal-icon-badge {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(200, 155, 60, 0.18);
    border: 1px solid rgba(200, 155, 60, 0.45);
    color: #E6BC5C;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}

.cert-modal-title {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0;
    color: #FFFFFF;
    letter-spacing: 0.01em;
    line-height: 1.25;
}

.cert-modal-subtitle {
    margin: 3px 0 0 0;
    font-size: 0.8rem;
    color: #D1DCCF;
    font-weight: 500;
}

.cert-modal-close {
    background: transparent;
    border: none;
    color: #A3B3A1;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 1.1rem;
    transition: all 0.15s ease;
}

.cert-modal-close:hover {
    background: rgba(255, 255, 255, 0.12);
    color: #FFFFFF;
}

/* ── Modal Body ──────────────────────────────────────────────────────── */
.cert-modal-body {
    padding: 22px 24px;
    background: #FAF8F5;
}

/* ── Record Summary Card ─────────────────────────────────────────────── */
.cert-record-card {
    background: #FFFFFF;
    border: 1px solid #E5DFD4;
    border-left: 4px solid #C89B3C;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 20px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
}

.cert-record-meta-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
}

.cert-record-ref {
    display: inline-flex;
    align-items: center;
    font-size: 0.78rem;
    font-weight: 700;
    color: #785215;
    background: #FAF3E3;
    border: 1px solid #EADBBA;
    padding: 2px 8px;
    border-radius: 5px;
    letter-spacing: 0.02em;
}

.cert-record-date {
    display: inline-flex;
    align-items: center;
    font-size: 0.78rem;
    font-weight: 600;
    color: #475569;
    background: #F1F5F9;
    padding: 2px 8px;
    border-radius: 5px;
}

.cert-record-name-wrap {
    margin-top: 4px;
}

.cert-record-name-label {
    display: block;
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    color: #8C7B62;
    letter-spacing: 0.05em;
    margin-bottom: 2px;
}

.cert-record-name {
    margin: 0;
    font-family: inherit;
    font-size: 1.05rem;
    font-weight: 800;
    color: #1E293B;
    line-height: 1.35;
    word-break: break-word;
}

/* ── Choice Options ──────────────────────────────────────────────────── */
.cert-selection-heading {
    display: block;
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #4A5568;
    letter-spacing: 0.04em;
    margin-bottom: 12px;
}

.cert-choice-group {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 18px;
}

.cert-choice-item {
    width: 100%;
}

.cert-choice-card {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 14px 16px;
    background: #FFFFFF;
    border: 1.5px solid #E2D8C3;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    user-select: none;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.02);
}

.cert-choice-card:hover {
    border-color: #C89B3C;
    background: #FFFDF8;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(200, 155, 60, 0.12);
}

/* Hide native radio input but keep focusable */
.cert-choice-card input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

/* Custom Radio Circle */
.cert-custom-radio {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid #CBD5E1;
    background: #FFFFFF;
    margin-top: 2px;
    flex-shrink: 0;
    position: relative;
    transition: all 0.2s ease;
}

.cert-choice-card input[type="radio"]:checked + .cert-custom-radio {
    border-color: #C89B3C;
    background: #2E3A2D;
}

.cert-choice-card input[type="radio"]:checked + .cert-custom-radio::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #DEB042;
}

/* Selected Card Highlight */
.cert-choice-card:has(input[type="radio"]:checked) {
    border-color: #C89B3C;
    background: #FFFDF7;
    box-shadow: 0 0 0 1px #C89B3C, 0 4px 14px rgba(200, 155, 60, 0.15);
}

.cert-choice-content {
    flex: 1;
}

.cert-choice-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 4px;
}

.cert-choice-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1E293B;
    display: inline-flex;
    align-items: center;
    gap: 7px;
}

.cert-icon-gold {
    color: #C89B3C;
}

.cert-icon-green {
    color: #2E3A2D;
}

.cert-badge-official {
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 4px;
    background: #FAF3E3;
    color: #925C0C;
    border: 1px solid #ECD9AA;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.cert-badge-certified {
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 4px;
    background: #EDF7EE;
    color: #1B5E20;
    border: 1px solid #C8E6C9;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.cert-choice-description {
    margin: 0;
    font-size: 0.82rem;
    color: #64748B;
    line-height: 1.4;
}

/* ── Notice Callout for Marriage & Funeral ───────────────────────────── */
.cert-modal-callout {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    background: #FEF8EC;
    border: 1px solid #F3E0B5;
    border-left: 4px solid #C89B3C;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #7B5412;
    line-height: 1.4;
}

.cert-modal-callout i {
    font-size: 1.1rem;
    color: #C89B3C;
    flex-shrink: 0;
}

/* ── Modal Actions ───────────────────────────────────────────────────── */
.cert-modal-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 6px;
    padding-top: 14px;
    border-top: 1px solid #EADBCE;
}

.btn-cert-cancel {
    height: 42px;
    padding: 0 18px;
    border: 1px solid #CBD5E1;
    border-radius: 8px;
    background: #FFFFFF;
    color: #475569;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
}

.btn-cert-cancel:hover {
    background: #F1F5F9;
    color: #1E293B;
    border-color: #94A3B8;
}

.btn-cert-generate {
    height: 42px;
    padding: 0 24px;
    border: 1px solid #C89B3C;
    border-radius: 8px;
    background: linear-gradient(135deg, #2E3A2D 0%, #1E271D 100%);
    color: #DEB042;
    font-size: 0.92rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    box-shadow: 0 3px 10px rgba(31, 41, 30, 0.28);
    transition: all 0.2s ease;
}

.btn-cert-generate:hover {
    background: linear-gradient(135deg, #384637 0%, #253124 100%);
    color: #F3CA68;
    border-color: #DEB042;
    transform: translateY(-1px);
    box-shadow: 0 5px 14px rgba(31, 41, 30, 0.38);
}

/* ── Dark Mode Styles ────────────────────────────────────────────────── */
[data-theme="dark"] .cert-modal-content,
body.dark-mode .cert-modal-content,
html.dark .cert-modal-content {
    background: #181E18;
    border-color: #384538;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.6);
}

[data-theme="dark"] .cert-modal-body,
body.dark-mode .cert-modal-body,
html.dark .cert-modal-body {
    background: #121712;
}

[data-theme="dark"] .cert-record-card,
body.dark-mode .cert-record-card,
html.dark .cert-record-card {
    background: #1C231C;
    border-color: #2F3B2F;
    border-left-color: #C89B3C;
}

[data-theme="dark"] .cert-record-name,
body.dark-mode .cert-record-name,
html.dark .cert-record-name {
    color: #F1F5F9;
}

[data-theme="dark"] .cert-record-name-label,
body.dark-mode .cert-record-name-label,
html.dark .cert-record-name-label {
    color: #A3967F;
}

[data-theme="dark"] .cert-record-date,
body.dark-mode .cert-record-date,
html.dark .cert-record-date {
    background: #273127;
    color: #CBD5E1;
}

[data-theme="dark"] .cert-selection-heading,
body.dark-mode .cert-selection-heading,
html.dark .cert-selection-heading {
    color: #94A3B8;
}

[data-theme="dark"] .cert-choice-card,
body.dark-mode .cert-choice-card,
html.dark .cert-choice-card {
    background: #1C231C;
    border-color: #2E3A2E;
}

[data-theme="dark"] .cert-choice-card:hover,
body.dark-mode .cert-choice-card:hover,
html.dark .cert-choice-card:hover {
    background: #242D24;
    border-color: #C89B3C;
}

[data-theme="dark"] .cert-choice-card:has(input[type="radio"]:checked),
body.dark-mode .cert-choice-card:has(input[type="radio"]:checked),
html.dark .cert-choice-card:has(input[type="radio"]:checked) {
    background: #253025;
    border-color: #C89B3C;
    box-shadow: 0 0 0 1px #C89B3C, 0 4px 14px rgba(0, 0, 0, 0.4);
}

[data-theme="dark"] .cert-choice-title,
body.dark-mode .cert-choice-title,
html.dark .cert-choice-title {
    color: #F8FAFC;
}

[data-theme="dark"] .cert-choice-description,
body.dark-mode .cert-choice-description,
html.dark .cert-choice-description {
    color: #94A3B8;
}

[data-theme="dark"] .cert-custom-radio,
body.dark-mode .cert-custom-radio,
html.dark .cert-custom-radio {
    background: #161C16;
    border-color: #4B5563;
}

[data-theme="dark"] .cert-modal-callout,
body.dark-mode .cert-modal-callout,
html.dark .cert-modal-callout {
    background: #2B2313;
    border-color: #5C4416;
    color: #F3D28E;
}

[data-theme="dark"] .cert-modal-actions,
body.dark-mode .cert-modal-actions,
html.dark .cert-modal-actions {
    border-top-color: #2E3A2E;
}

[data-theme="dark"] .btn-cert-cancel,
body.dark-mode .btn-cert-cancel,
html.dark .btn-cert-cancel {
    background: #1C231C;
    border-color: #3E4D3E;
    color: #CBD5E1;
}

[data-theme="dark"] .btn-cert-cancel:hover,
body.dark-mode .btn-cert-cancel:hover,
html.dark .btn-cert-cancel:hover {
    background: #273127;
    color: #F8FAFC;
}

/* ── Mobile Viewport Optimizations ───────────────────────────────────── */
@media (max-width: 576px) {
    .cert-modal-dialog {
        max-width: 100%;
        margin: 0;
    }
    .cert-modal-header {
        padding: 14px 16px;
    }
    .cert-modal-title {
        font-size: 1.12rem;
    }
    .cert-modal-body {
        padding: 16px;
    }
    .cert-record-card {
        padding: 12px;
        margin-bottom: 16px;
    }
    .cert-choice-card {
        padding: 12px;
    }
    .cert-modal-actions {
        flex-direction: column-reverse;
        gap: 8px;
    }
    .btn-cert-cancel,
    .btn-cert-generate {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
(function() {
    'use strict';

    /**
     * Opens the certificate generation modal with record context.
     * @param {HTMLElement} btn - The trigger button containing dataset attributes
     */
    window.openCertModal = function(btn) {
        if (!btn) return;
        const registry = (btn.getAttribute('data-registry') || '').toLowerCase().trim();
        const id = btn.getAttribute('data-id') || '';
        const name = btn.getAttribute('data-name') || 'N/A';
        const ref = btn.getAttribute('data-ref') || ('#' + id);
        const date = btn.getAttribute('data-date') || '';
        const dateLabel = btn.getAttribute('data-date-label') || 'Date';

        const modal = document.getElementById('certGenerationModal');
        if (!modal) return;

        // Populate hidden fields and record details
        document.getElementById('certModalRegistryInput').value = registry;
        document.getElementById('certModalRecordIdInput').value = id;
        document.getElementById('certModalRecordName').textContent = name;
        document.getElementById('certModalRefNo').innerHTML = '<i class="fas fa-hashtag me-1"></i>Ref: ' + escapeHtml(ref);
        
        const dateBadge = document.getElementById('certModalDateBadge');
        if (date) {
            dateBadge.innerHTML = '<i class="fas fa-calendar-alt me-1"></i>' + escapeHtml(dateLabel) + ': ' + escapeHtml(date);
            dateBadge.style.display = 'inline-flex';
        } else {
            dateBadge.style.display = 'none';
        }

        const origWrap = document.getElementById('certChoiceOriginalWrap');
        const radioOrig = document.getElementById('certTypeOriginal');
        const radioCert = document.getElementById('certTypeCertification');
        const notice = document.getElementById('certModalNotice');
        const noticeText = document.getElementById('certModalNoticeText');
        const registryBadge = document.getElementById('certModalRegistryBadge');

        const registryTitles = {
            'baptism': 'Baptismal Registry Record',
            'communion': 'First Communion Registry Record',
            'first_communion': 'First Communion Registry Record',
            'confirmation': 'Confirmation Registry Record',
            'marriage': 'Marriage Registry Record',
            'funeral': 'Funeral / Burial Registry Record'
        };
        registryBadge.textContent = registryTitles[registry] || (registry.charAt(0).toUpperCase() + registry.slice(1) + ' Record');

        // Enforcement: Marriage and Funeral are Certification ONLY
        if (registry === 'marriage' || registry === 'funeral') {
            origWrap.style.display = 'none';
            radioOrig.disabled = true;
            radioOrig.checked = false;

            radioCert.disabled = false;
            radioCert.checked = true;

            const regDisplayName = registry === 'marriage' ? 'Marriage' : 'Funeral';
            noticeText.textContent = `${regDisplayName} records are issued as Certification only.`;
            notice.style.display = 'flex';
        } else {
            // Baptism, First Communion, Confirmation: show both choices
            origWrap.style.display = 'block';
            radioOrig.disabled = false;
            radioOrig.checked = true; // default choice

            radioCert.disabled = false;
            notice.style.display = 'none';
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    /**
     * Closes the certificate generation modal.
     */
    window.closeCertModal = function() {
        const modal = document.getElementById('certGenerationModal');
        if (!modal) return;
        modal.style.display = 'none';
        document.body.style.overflow = '';
    };

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Dismiss by clicking outside modal content (on the backdrop)
    document.addEventListener('click', function(e) {
        const modal = document.getElementById('certGenerationModal');
        if (modal && modal.style.display !== 'none' && e.target === modal) {
            window.closeCertModal();
        }
    });

    // Dismiss with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' || e.key === 'Esc') {
            const modal = document.getElementById('certGenerationModal');
            if (modal && modal.style.display !== 'none') {
                window.closeCertModal();
            }
        }
    });

    // Event delegation: Trigger modal when clicking any element with .btn-open-cert-modal
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-open-cert-modal');
        if (btn) {
            e.preventDefault();
            window.openCertModal(btn);
        }
    });
})();
</script>
