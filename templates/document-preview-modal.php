<?php
/**
 * Canonical Document Preview Modal Component
 * Used across admin workflow and parishioner view pages.
 * Supports inline rendering for PDF and image files, with fallback for legacy files.
 */
?>
<!-- Supporting Document Preview Modal -->
<div class="modal fade" id="documentPreviewModal" tabindex="-1" aria-labelledby="docPreviewModalLabel" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2 text-truncate me-3">
                    <div class="p-2 rounded bg-primary-subtle text-primary flex-shrink-0">
                        <i class="fas fa-file-lines fa-lg"></i>
                    </div>
                    <div class="min-w-0">
                        <h5 class="modal-title fw-bold text-dark mb-0 text-truncate" id="docPreviewModalLabel">Document Preview</h5>
                        <div class="text-muted small text-truncate" id="docPreviewMeta">Loading document details...</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <a id="docPreviewExternalBtn" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary d-none d-sm-inline-flex align-items-center gap-1.5" title="Open in new tab">
                        <i class="fas fa-arrow-up-right-from-square"></i>
                        <span>Open Tab</span>
                    </a>
                    <a id="docPreviewDownloadBtn" href="#" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1.5 fw-semibold" download title="Download file to device">
                        <i class="fas fa-download"></i>
                        <span>Download</span>
                    </a>
                    <button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-0 position-relative d-flex flex-column align-items-center justify-content-center" style="min-height: 520px; background-color: #0f172a10;">
                
                <!-- Loading State -->
                <div id="docPreviewLoader" class="text-center py-5">
                    <div class="spinner-border text-primary mb-3" role="status" style="width: 3.2rem; height: 3.2rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="fw-bold text-dark fs-6">Loading Document Content...</div>
                    <div class="text-muted small mt-1">Retrieving and verifying the file stream.</div>
                </div>

                <!-- Error State -->
                <div id="docPreviewError" class="text-center py-5 px-4" style="display: none;">
                    <div class="mb-3 text-danger">
                        <i class="fas fa-triangle-exclamation fa-3x"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-2">Unable to Render Preview</h5>
                    <p id="docPreviewErrorMessage" class="text-secondary small mb-4" style="max-width: 480px; margin: 0 auto;">
                        The browser could not display this document directly. You can still download the file to inspect it securely on your device.
                    </p>
                    <a id="docPreviewErrorDownloadBtn" href="#" class="btn btn-primary px-4 py-2 fw-semibold d-inline-flex align-items-center gap-2" download>
                        <i class="fas fa-download"></i>
                        <span>Download Document</span>
                    </a>
                </div>

                <!-- Fallback Container (for legacy non-previewable formats like .docx, .zip) -->
                <div id="docPreviewFallback" class="text-center py-5 px-4" style="display: none;">
                    <div class="mb-3 text-secondary">
                        <i class="fas fa-file-arrow-down fa-3x text-primary"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">In-Browser Preview Not Available</h5>
                    <p class="text-muted small mb-3" style="max-width: 460px; margin: 0 auto;">
                        This file format cannot be rendered directly inside the browser. Use the download button below to view the file on your device.
                    </p>
                    <div class="badge bg-light text-dark border px-3 py-2 mb-4 font-monospace" id="docPreviewFallbackFileName">filename.ext</div>
                    <div>
                        <a id="docPreviewFallbackDownloadBtn" href="#" class="btn btn-primary px-4 py-2 fw-semibold d-inline-flex align-items-center gap-2" download>
                            <i class="fas fa-download"></i>
                            <span>Download File</span>
                        </a>
                    </div>
                </div>

                <!-- Image Viewer Container -->
                <div id="docPreviewImageContainer" class="w-100 h-100 p-3 text-center d-flex align-items-center justify-content-center overflow-auto" style="display: none;">
                    <img id="docPreviewImage" src="" alt="Document Preview" class="img-fluid rounded shadow-sm" style="max-height: 76vh; max-width: 100%; object-fit: contain; display: none;">
                </div>

                <!-- PDF Viewer Container -->
                <div id="docPreviewPdfContainer" class="w-100 h-100" style="display: none;">
                    <iframe id="docPreviewPdfFrame" src="about:blank" class="w-100 border-0" style="height: 78vh; min-height: 520px; display: block;" title="Document PDF Preview"></iframe>
                </div>

            </div>
            <div class="modal-footer bg-white border-top py-2.5 px-4 d-flex justify-content-between align-items-center">
                <div class="text-muted small">
                    <i class="fas fa-shield-halved text-success me-1"></i> Verified authenticated document stream
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Unified Document Viewer Controller
(function () {
    let initialized = false;
    let currentDocId = null;
    let activeBlobUrl = null;
    let activeAbortController = null;

    function initDocViewer() {
        if (initialized) return;
        const previewModalEl = document.getElementById('documentPreviewModal');
        if (!previewModalEl) return;
        initialized = true;

        const modalLabel = document.getElementById('docPreviewModalLabel');
        const modalMeta = document.getElementById('docPreviewMeta');
        const externalBtn = document.getElementById('docPreviewExternalBtn');
        const downloadBtn = document.getElementById('docPreviewDownloadBtn');
        const errorDownloadBtn = document.getElementById('docPreviewErrorDownloadBtn');
        const fallbackDownloadBtn = document.getElementById('docPreviewFallbackDownloadBtn');
        const fallbackFileName = document.getElementById('docPreviewFallbackFileName');
        
        const loader = document.getElementById('docPreviewLoader');
        const errorBox = document.getElementById('docPreviewError');
        const errorMsg = document.getElementById('docPreviewErrorMessage');
        const fallbackBox = document.getElementById('docPreviewFallback');
        const imgContainer = document.getElementById('docPreviewImageContainer');
        const imgElement = document.getElementById('docPreviewImage');
        const pdfContainer = document.getElementById('docPreviewPdfContainer');
        const pdfFrame = document.getElementById('docPreviewPdfFrame');

        function cleanupActiveStream() {
            if (activeAbortController) {
                try { activeAbortController.abort(); } catch (e) {}
                activeAbortController = null;
            }
            if (activeBlobUrl) {
                try { URL.revokeObjectURL(activeBlobUrl); } catch (e) {}
                activeBlobUrl = null;
            }
            if (imgElement) {
                imgElement.onload = null;
                imgElement.onerror = null;
                imgElement.removeAttribute('src');
                imgElement.style.display = 'none';
            }
            if (pdfFrame) {
                pdfFrame.onload = null;
                pdfFrame.onerror = null;
                pdfFrame.src = 'about:blank';
            }
        }

        function showErrorState(message) {
            if (loader) loader.style.display = 'none';
            if (imgContainer) imgContainer.style.display = 'none';
            if (pdfContainer) pdfContainer.style.display = 'none';
            if (fallbackBox) fallbackBox.style.display = 'none';
            if (errorMsg) {
                errorMsg.textContent = message || 'Unable to preview this file — try downloading it instead.';
            }
            if (errorBox) errorBox.style.display = 'block';
        }

        function getBaseDocumentUrl() {
            const path = window.location.pathname || '';
            if (path.indexOf('/admin/') !== -1 || path.indexOf('/users/') !== -1) {
                return '../request-document.php';
            }
            return 'request-document.php';
        }

        function renderDocPreview(button) {
            if (!button) return;
            const docId = button.getAttribute('data-doc-id');
            if (!docId) return;

            if (currentDocId === docId && activeBlobUrl) {
                return;
            }
            currentDocId = docId;

            const docName = button.getAttribute('data-doc-name') || 'Document Preview';
            const docFile = button.getAttribute('data-doc-file') || '';
            const docSize = button.getAttribute('data-doc-size') || '';
            const docMime = (button.getAttribute('data-doc-mime') || '').toLowerCase();

            cleanupActiveStream();

            if (loader) loader.style.display = 'block';
            if (errorBox) errorBox.style.display = 'none';
            if (fallbackBox) fallbackBox.style.display = 'none';
            if (imgContainer) imgContainer.style.display = 'none';
            if (pdfContainer) pdfContainer.style.display = 'none';

            const baseDocPath = getBaseDocumentUrl();
            const previewUrl = baseDocPath + '?id=' + encodeURIComponent(docId);
            const downloadUrl = baseDocPath + '?id=' + encodeURIComponent(docId) + '&download=1';

            if (modalLabel) modalLabel.textContent = docName;
            if (modalMeta) modalMeta.textContent = (docFile || 'Document file') + (docSize ? ' • ' + docSize : '');
            
            if (externalBtn) externalBtn.href = previewUrl;
            if (downloadBtn) downloadBtn.href = downloadUrl;
            if (errorDownloadBtn) errorDownloadBtn.href = downloadUrl;
            if (fallbackDownloadBtn) fallbackDownloadBtn.href = downloadUrl;
            if (fallbackFileName) fallbackFileName.textContent = docFile || 'Attached Document';

            const ext = (docFile.split('.').pop() || '').toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg'].includes(ext) || (docMime && docMime.startsWith('image/'));
            const isPdf = ext === 'pdf' || docMime === 'application/pdf';

            if (!isImage && !isPdf) {
                if (loader) loader.style.display = 'none';
                if (fallbackBox) fallbackBox.style.display = 'block';
                return;
            }

            activeAbortController = new AbortController();
            const fetchSignal = activeAbortController.signal;

            fetch(previewUrl, {
                signal: fetchSignal,
                credentials: 'same-origin',
                headers: {
                    'Accept': isImage ? 'image/*,*/*' : 'application/pdf,*/*'
                }
            })
            .then(async function (response) {
                if (currentDocId !== docId) return;

                if (!response.ok) {
                    let errText = 'Unable to preview this file — try downloading it instead.';
                    if (response.status === 404) {
                        errText = 'The requested document file was not found on server storage. Try downloading it instead.';
                    } else if (response.status === 403) {
                        errText = 'Access denied. You do not have permission to view this document.';
                    } else if (response.status >= 500) {
                        errText = 'A server error occurred while retrieving this document. Try downloading it instead.';
                    }
                    showErrorState(errText);
                    return;
                }

                const contentType = (response.headers.get('content-type') || '').toLowerCase();
                if (contentType.includes('application/json')) {
                    try {
                        const json = await response.json();
                        showErrorState(json.message || 'Unable to preview this file — try downloading it instead.');
                        return;
                    } catch (e) {}
                }

                const blob = await response.blob();
                if (currentDocId !== docId) return;

                if (!blob || blob.size === 0) {
                    showErrorState('The uploaded document is empty or unreadable. Try downloading it instead.');
                    return;
                }

                const resolvedBlobUrl = URL.createObjectURL(blob);
                activeBlobUrl = resolvedBlobUrl;

                if (isImage) {
                    if (imgContainer && imgElement) {
                        imgElement.onload = function () {
                            if (currentDocId !== docId) return;
                            if (loader) loader.style.display = 'none';
                            if (errorBox) errorBox.style.display = 'none';
                            if (fallbackBox) fallbackBox.style.display = 'none';
                            if (imgContainer) imgContainer.style.display = 'flex';
                            imgElement.style.display = 'block';
                        };
                        imgElement.onerror = function () {
                            if (currentDocId !== docId) return;
                            showErrorState('Unable to preview this image file — try downloading it instead.');
                        };
                        imgElement.src = resolvedBlobUrl;
                    }
                } else if (isPdf) {
                    if (pdfContainer && pdfFrame) {
                        pdfContainer.style.display = 'block';
                        pdfFrame.onload = function () {
                            if (currentDocId !== docId) return;
                            if (loader) loader.style.display = 'none';
                            if (errorBox) errorBox.style.display = 'none';
                        };
                        pdfFrame.onerror = function () {
                            if (currentDocId !== docId) return;
                            showErrorState('Unable to preview this PDF document — try downloading it instead.');
                        };
                        pdfFrame.src = resolvedBlobUrl;
                    }
                }
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                if (currentDocId !== docId) return;
                console.warn('Document preview fetch error:', err);
                showErrorState('Unable to preview this file — try downloading it instead.');
            });
        }

        // Global click listener for .btn-preview-doc
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-preview-doc');
            if (btn) {
                renderDocPreview(btn);
                if (window.bootstrap && window.bootstrap.Modal) {
                    try {
                        const modalInstance = bootstrap.Modal.getOrCreateInstance(previewModalEl);
                        modalInstance.show();
                    } catch (err) {
                        console.warn('Bootstrap modal show failed:', err);
                    }
                }
            }
        });

        previewModalEl.addEventListener('show.bs.modal', function (event) {
            renderDocPreview(event.relatedTarget);
        });

        previewModalEl.addEventListener('hidden.bs.modal', function () {
            cleanupActiveStream();
            currentDocId = null;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDocViewer);
    } else {
        initDocViewer();
    }
    window.addEventListener('load', initDocViewer);
})();
</script>
