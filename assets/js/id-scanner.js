/**
 * IDScanner — GCash-style camera viewfinder for Philippine ID capture.
 *
 * Usage:
 *   const dataUrl = await window.IDScanner.openModal('front');
 *   // or
 *   const dataUrl = await window.IDScanner.openModal('back');
 *
 * The modal renders a full-screen dark overlay with an ID-1 ratio cutout
 * (85.60 × 53.98 mm, aspect ~1.586), animated scan laser, corner guides,
 * and dynamic helper text. When the user holds the ID stable for ~1.2 s,
 * the frame is auto-cropped and returned as a JPEG base64 data URL.
 *
 * Returns: Promise<string>  — resolves to base64 image data URL on success
 *                           — rejects on cancel or camera error
 */
(function (global) {
    'use strict';

    /* ─── Constants ──────────────────────────────────────────────────────── */
    const ID_RATIO   = 85.60 / 53.98; // ISO/IEC 7810 ID-1 = 1.58553…
    const STABLE_MS  = 1200;           // hold-still duration before auto-shutter
    const LASER_DUR  = 2000;           // one full laser sweep in ms
    const HINTS      = [
        { text: 'Center your ID inside the frame',            icon: '📄' },
        { text: 'Hold still — keep the ID flat and steady',   icon: '✋' },
        { text: 'Make sure all four corners are visible',     icon: '🔲' },
        { text: 'Ensure good lighting — avoid glare',         icon: '💡' },
    ];

    /* ─── Styles (injected once) ─────────────────────────────────────────── */
    function injectStyles() {
        if (document.getElementById('id-scanner-styles')) return;
        const css = `
#id-scanner-overlay {
    position: fixed; inset: 0; z-index: 99999;
    background: rgba(0,0,0,0.94);
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    opacity: 0; transition: opacity .22s ease;
    font-family: -apple-system, 'Inter', 'Segoe UI', sans-serif;
    -webkit-tap-highlight-color: transparent;
}
#id-scanner-overlay.is-visible { opacity: 1; }

/* ── Header ──────────────────────────────────────────────── */
.ids-header {
    position: absolute; top: 0; left: 0; right: 0;
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 20px 14px;
    background: linear-gradient(to bottom, rgba(0,0,0,.88) 0%, transparent 100%);
    z-index: 4;
}
.ids-title {
    color: #fff; font-size: 15px; font-weight: 600;
    letter-spacing: .3px;
    display: flex; align-items: center; gap: 8px;
}
.ids-side-badge {
    display: inline-flex; align-items: center;
    color: #fff; font-size: 11px; font-weight: 700;
    padding: 3px 10px; border-radius: 20px; letter-spacing: .5px;
    text-transform: uppercase;
}
.ids-close-btn {
    background: rgba(255,255,255,.12); border: none; color: #fff;
    width: 36px; height: 36px; border-radius: 50%; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; transition: background .18s; flex-shrink: 0;
}
.ids-close-btn:hover { background: rgba(255,255,255,.25); }

/* ── Video ───────────────────────────────────────────────── */
#ids-video {
    position: absolute; inset: 0;
    width: 100%; height: 100%;
    object-fit: cover;
}
#ids-video.mirror { transform: scaleX(-1); }

/* ── Status bar ──────────────────────────────────────────── */
.ids-status-bar {
    position: absolute; z-index: 3;
    top: 76px; left: 0; right: 0;
    text-align: center; pointer-events: none;
}
.ids-status-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(0,0,0,.62); backdrop-filter: blur(6px);
    border: 1px solid rgba(255,255,255,.14);
    color: rgba(255,255,255,.85); font-size: 12px; font-weight: 500;
    padding: 5px 14px; border-radius: 20px;
    transition: all .3s;
}
.ids-status-pill.status-stable    { border-color: rgba(100,220,100,.55); color: #90EE90; }
.ids-status-pill.status-error     { border-color: rgba(255,100,100,.45); color: #FFB3B3; }
.ids-status-dot {
    width: 7px; height: 7px; border-radius: 50%;
    display: inline-block; flex-shrink: 0;
    animation: ids-pulse-dot 1s ease-in-out infinite;
}

/* ── Frame wrap ──────────────────────────────────────────── */
.ids-frame-wrap {
    position: relative; z-index: 2;
    width: min(88vw, 540px);
}
.ids-frame {
    position: relative;
    width: 100%;
    padding-top: 63.04%;   /* 100% / 1.58553 = 63.04% */
    border-radius: 10px;
    overflow: hidden;
    /* The box-shadow creates the dark mask around the cutout */
    box-shadow:
        0 0 0 2px rgba(212,169,78,.45),
        0 0 0 4000px rgba(0,0,0,.68);
}

/* ── Corner guides ───────────────────────────────────────── */
.ids-corner {
    position: absolute;
    width: 26px; height: 26px;
    border-color: #D4A94E; border-style: solid;
    pointer-events: none;
}
.ids-corner-tl { top: -1px; left: -1px;   border-width: 3px 0 0 3px; border-radius: 8px 0 0 0; }
.ids-corner-tr { top: -1px; right: -1px;  border-width: 3px 3px 0 0; border-radius: 0 8px 0 0; }
.ids-corner-br { bottom: -1px; right: -1px; border-width: 0 3px 3px 0; border-radius: 0 0 8px 0; }
.ids-corner-bl { bottom: -1px; left: -1px;  border-width: 0 0 3px 3px; border-radius: 0 0 0 8px; }

/* ── Laser line ──────────────────────────────────────────── */
.ids-laser {
    position: absolute; left: 0; right: 0;
    height: 2.5px;
    background: linear-gradient(90deg,
        transparent 0%, rgba(212,169,78,.35) 10%,
        #D4A94E 30%, #FFDF7E 50%,
        #D4A94E 70%, rgba(212,169,78,.35) 90%,
        transparent 100%);
    box-shadow: 0 0 10px 3px rgba(212,169,78,.5);
    top: 0;
    animation: ids-laser-sweep var(--laser-dur, 2000ms) ease-in-out infinite;
}
@keyframes ids-laser-sweep {
    0%   { top: 4%; opacity: .85; }
    47%  { top: 91%; opacity: .85; }
    50%  { top: 91%; opacity: 0; }
    53%  { top: 4%; opacity: 0; }
    56%  { top: 4%; opacity: .85; }
    100% { top: 4%; opacity: .85; }
}

/* ── Countdown ring ──────────────────────────────────────── */
.ids-progress-ring {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    pointer-events: none; opacity: 0; transition: opacity .3s;
}
.ids-progress-ring.is-active { opacity: 1; }
.ids-progress-ring svg { width: 70px; height: 70px; filter: drop-shadow(0 0 6px rgba(212,169,78,.4)); }
.ids-ring-bg  { fill: rgba(0,0,0,.4); stroke: rgba(255,255,255,.12); stroke-width: 4; }
.ids-ring-arc {
    fill: none; stroke: #D4A94E; stroke-width: 4;
    stroke-linecap: round;
    transform: rotate(-90deg); transform-origin: 50% 50%;
    transition: stroke-dashoffset .12s linear;
}
.ids-ring-check {
    font-size: 22px; position: absolute;
    line-height: 1; user-select: none;
}

/* ── Flash ───────────────────────────────────────────────── */
.ids-flash {
    position: absolute; inset: 0;
    background: #fff; opacity: 0; pointer-events: none; z-index: 10;
    transition: opacity .06s;
}
.ids-flash.is-active { opacity: .72; }

/* ── Helper hint ─────────────────────────────────────────── */
.ids-hint {
    position: absolute; z-index: 3;
    bottom: 0; left: 0; right: 0;
    background: linear-gradient(to top, rgba(0,0,0,.9) 0%, transparent 100%);
    padding: 22px 16px 24px;
    text-align: center; pointer-events: none;
}
.ids-hint-text {
    color: rgba(255,255,255,.92); font-size: 13.5px; font-weight: 500;
    opacity: 0; transform: translateY(7px);
    transition: opacity .28s ease, transform .28s ease;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ids-hint-text.is-visible { opacity: 1; transform: translateY(0); }
.ids-hint-icon { font-size: 17px; margin-right: 5px; }

/* ── Capture button ──────────────────────────────────────── */
.ids-capture-btn {
    margin-top: 22px; z-index: 3; position: relative;
    background: linear-gradient(135deg, #D4A94E 0%, #B07D2A 100%);
    color: #fff; border: none; border-radius: 50px;
    padding: 14px 36px; font-size: 15px; font-weight: 600;
    cursor: pointer; letter-spacing: .3px;
    box-shadow: 0 4px 18px rgba(180,120,30,.5);
    transition: transform .15s, box-shadow .15s, opacity .15s;
    display: flex; align-items: center; gap: 9px;
    -webkit-tap-highlight-color: transparent;
}
.ids-capture-btn:hover { transform: translateY(-2px); box-shadow: 0 7px 22px rgba(180,120,30,.58); }
.ids-capture-btn:active { transform: scale(.97); }
.ids-capture-btn.is-capturing { opacity: .55; pointer-events: none; }
.ids-capture-btn .ids-btn-icon { font-size: 16px; }

/* ── Tip strip ───────────────────────────────────────────── */
.ids-tip-strip {
    position: absolute; bottom: 0; left: 0; right: 0; z-index: 3;
    display: flex; justify-content: center; gap: 20px;
    padding: 0 0 14px;
    pointer-events: none;
}
.ids-tip {
    display: flex; flex-direction: column; align-items: center; gap: 3px;
    color: rgba(255,255,255,.55); font-size: 10px; text-align: center;
}
.ids-tip i { font-size: 13px; }

/* ── Canvas (off-screen) ─────────────────────────────────── */
#ids-canvas { display: none; }

@keyframes ids-pulse-dot {
    0%,100%{ opacity:1; transform:scale(1); }
    50%{ opacity:.4; transform:scale(.72); }
}
`;
        const tag = document.createElement('style');
        tag.id = 'id-scanner-styles';
        tag.textContent = css;
        document.head.appendChild(tag);
    }

    /* ─── Build DOM ──────────────────────────────────────────────────────── */
    function buildOverlay(side) {
        const overlay = document.createElement('div');
        overlay.id = 'id-scanner-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Philippine ID Scanner');

        const isFront = side !== 'back';
        const sideLabel = isFront ? 'FRONT SIDE' : 'BACK SIDE';
        const accentColor = isFront ? '#D4A94E' : '#7EAECF';
        const gradStart   = isFront ? '#D4A94E' : '#7EAECF';
        const gradEnd     = isFront ? '#B07D2A' : '#4A7A9B';

        overlay.innerHTML = `
<video id="ids-video" playsinline muted autoplay></video>
<canvas id="ids-canvas"></canvas>

<div class="ids-header">
  <div class="ids-title">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="${accentColor}"
         stroke-width="2.2" style="flex-shrink:0">
      <rect x="2" y="5" width="20" height="14" rx="2"/>
      <circle cx="8" cy="12" r="2.3"/>
      <line x1="13" y1="9.5" x2="20" y2="9.5"/>
      <line x1="13" y1="12.5" x2="20" y2="12.5"/>
      <line x1="13" y1="15.5" x2="17.5" y2="15.5"/>
    </svg>
    Scan Philippine ID
    <span class="ids-side-badge"
          style="background:linear-gradient(135deg,${gradStart},${gradEnd})">${sideLabel}</span>
  </div>
  <button class="ids-close-btn" id="ids-close-btn" aria-label="Cancel scanner" type="button">✕</button>
</div>

<div class="ids-status-bar">
  <div class="ids-status-pill" id="ids-status-pill">
    <span class="ids-status-dot" id="ids-status-dot" style="background:${accentColor}"></span>
    <span id="ids-status-text">Starting camera…</span>
  </div>
</div>

<div class="ids-frame-wrap">
  <div class="ids-frame" id="ids-frame">
    <div class="ids-laser" id="ids-laser" style="--laser-dur:${LASER_DUR}ms;
         background:linear-gradient(90deg,transparent 0%,rgba(${isFront?'212,169,78':'126,174,207'},.35) 10%,
         ${accentColor} 30%,${isFront?'#FFDF7E':'#B8D9F0'} 50%,
         ${accentColor} 70%,rgba(${isFront?'212,169,78':'126,174,207'},.35) 90%,transparent 100%);
         box-shadow:0 0 10px 3px rgba(${isFront?'212,169,78':'126,174,207'},.5)"></div>

    <div class="ids-progress-ring" id="ids-progress-ring">
      <svg viewBox="0 0 72 72">
        <circle class="ids-ring-bg" cx="36" cy="36" r="28"/>
        <circle class="ids-ring-arc" id="ids-ring-arc"
                cx="36" cy="36" r="28"
                stroke-dasharray="176" stroke-dashoffset="176"
                stroke="${accentColor}"/>
      </svg>
      <span class="ids-ring-check" id="ids-ring-check"></span>
    </div>

    <div class="ids-corner ids-corner-tl" style="border-color:${accentColor}"></div>
    <div class="ids-corner ids-corner-tr" style="border-color:${accentColor}"></div>
    <div class="ids-corner ids-corner-br" style="border-color:${accentColor}"></div>
    <div class="ids-corner ids-corner-bl" style="border-color:${accentColor}"></div>

    <div class="ids-flash" id="ids-flash"></div>

    <div class="ids-hint">
      <div class="ids-hint-text is-visible" id="ids-hint-text">
        <span class="ids-hint-icon">📄</span> Center your ID inside the frame
      </div>
    </div>
  </div>
</div>

<button class="ids-capture-btn" id="ids-capture-btn" type="button"
        style="background:linear-gradient(135deg,${gradStart},${gradEnd})">
  <span class="ids-btn-icon">📸</span> Capture ID
</button>
`;
        return overlay;
    }

    /* ─── Core scanner class ─────────────────────────────────────────────── */
    class ScannerInstance {
        constructor(side) {
            this.side         = side || 'front';
            this.stream       = null;
            this.stableStart  = 0;
            this.lastVariance = null;
            this.hintIndex    = 0;
            this._loopActive  = false;
            this.capturing    = false;
            this.resolve      = null;
            this.reject       = null;
            this.overlay      = null;
        }

        open() {
            return new Promise((resolve, reject) => {
                this.resolve = resolve;
                this.reject  = reject;
                injectStyles();
                this.overlay = buildOverlay(this.side);
                document.body.appendChild(this.overlay);
                this._bindRefs();
                this._bindEvents();
                requestAnimationFrame(() => this.overlay.classList.add('is-visible'));
                this._startCamera();
                this._cycleHints();
            });
        }

        _bindRefs() {
            const q = (s) => this.overlay.querySelector(s);
            this.video      = q('#ids-video');
            this.canvas     = q('#ids-canvas');
            this.closeBtn   = q('#ids-close-btn');
            this.captureBtn = q('#ids-capture-btn');
            this.hintEl     = q('#ids-hint-text');
            this.statusPill = q('#ids-status-pill');
            this.statusTxt  = q('#ids-status-text');
            this.statusDot  = q('#ids-status-dot');
            this.ring       = q('#ids-progress-ring');
            this.ringArc    = q('#ids-ring-arc');
            this.ringCheck  = q('#ids-ring-check');
            this.flash      = q('#ids-flash');
        }

        _bindEvents() {
            this.closeBtn.addEventListener('click', () => this._cancel());
            this.captureBtn.addEventListener('click', () => this._capture());
            this._onKey = (e) => { if (e.key === 'Escape') this._cancel(); };
            document.addEventListener('keydown', this._onKey);
        }

        async _startCamera() {
            if (!navigator.mediaDevices?.getUserMedia) {
                this._setStatus('Camera not supported in this browser.', 'error');
                return;
            }
            try {
                // Always use rear camera for ID scanning
                try {
                    this.stream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: { ideal: 'environment' },
                            width:  { ideal: 1920 },
                            height: { ideal: 1080 }
                        },
                        audio: false
                    });
                } catch (_) {
                    this.stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                }

                // Enable continuous autofocus if available
                const track = this.stream.getVideoTracks()[0];
                try {
                    const caps = track.getCapabilities?.() || {};
                    if (caps.focusMode?.includes('continuous')) {
                        await track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] });
                    }
                } catch (_) {}

                this.video.srcObject = this.stream;
                await this.video.play().catch(() => {});
                this._setStatus('Center your ID inside the frame');
                this._loopActive = true;
                this._detectionLoop();
            } catch (err) {
                const blocked = ['NotAllowedError', 'SecurityError'].includes(err?.name);
                this._setStatus(
                    blocked
                        ? 'Camera permission denied — please allow access and retry.'
                        : 'Could not start camera. Try uploading an ID image instead.',
                    'error'
                );
            }
        }

        _detectionLoop() {
            if (!this._loopActive || this.capturing) return;
            this._detectStability();
            setTimeout(() => this._detectionLoop(), 180);
        }

        _detectStability() {
            if (!this.video.videoWidth || this.video.readyState < 2) return;
            try {
                if (!this._sCtx) {
                    const c = document.createElement('canvas');
                    c.width = 80; c.height = 50;
                    this._sCtx = c.getContext('2d');
                }
                this._sCtx.drawImage(this.video, 0, 0, 80, 50);
                const d = this._sCtx.getImageData(0, 0, 80, 50).data;
                let sum = 0, sum2 = 0, n = d.length / 4;
                for (let i = 0; i < d.length; i += 4) {
                    const l = (d[i] * 299 + d[i+1] * 587 + d[i+2] * 114) / 1000;
                    sum += l; sum2 += l * l;
                }
                const mean = sum / n;
                const variance = (sum2 / n) - mean * mean;
                const diff = this.lastVariance !== null ? Math.abs(variance - this.lastVariance) : 999;
                this.lastVariance = variance;

                const isStable = diff < 10 && mean > 18 && mean < 240;

                if (isStable) {
                    if (!this.stableStart) {
                        this.stableStart = Date.now();
                        this.ring.classList.add('is-active');
                        this._setStatus('Hold still — capturing in a moment…', 'stable');
                    }
                    const p = Math.min((Date.now() - this.stableStart) / STABLE_MS, 1);
                    this.ringArc.style.strokeDashoffset = 176 * (1 - p);
                    if (p >= 1) this._capture();
                } else {
                    if (this.stableStart) {
                        this.stableStart = 0;
                        this.ring.classList.remove('is-active');
                        this.ringArc.style.strokeDashoffset = 176;
                        this._setStatus('Hold still — keep the ID flat and steady');
                    }
                }
            } catch (_) {}
        }

        async _capture() {
            if (this.capturing) return;
            this.capturing = true;
            this._loopActive = false;
            this.captureBtn.classList.add('is-capturing');
            this._setStatus('Capturing…', 'stable');
            this.ringCheck.textContent = '✓';

            // Flash
            this.flash.classList.add('is-active');
            await new Promise(r => setTimeout(r, 100));
            this.flash.classList.remove('is-active');

            try {
                const dataUrl = this._cropIdFrame();
                await new Promise(r => setTimeout(r, 80));
                this._teardown();
                this.resolve(dataUrl);
            } catch (err) {
                this.capturing = false;
                this._loopActive = true;
                this.captureBtn.classList.remove('is-capturing');
                this._setStatus('Capture failed — please try again.', 'error');
                this._detectionLoop();
            }
        }

        _cropIdFrame() {
            const v = this.video;
            if (!v.videoWidth || v.readyState < 2) throw new Error('Camera stream not ready.');

            const vw = v.videoWidth, vh = v.videoHeight;
            const maxW = vw * 0.92, maxH = vh * 0.82;
            let cropW, cropH;
            if (maxW / maxH > ID_RATIO) {
                cropH = maxH; cropW = cropH * ID_RATIO;
            } else {
                cropW = maxW; cropH = cropW / ID_RATIO;
            }
            const cropX = (vw - cropW) / 2, cropY = (vh - cropH) / 2;

            const outW = 1800, outH = Math.round(outW / ID_RATIO);
            const c = this.canvas;
            c.width = outW; c.height = outH;
            c.getContext('2d').drawImage(v, cropX, cropY, cropW, cropH, 0, 0, outW, outH);
            return c.toDataURL('image/jpeg', 0.95);
        }

        _cancel() {
            if (this.capturing) return;
            this._teardown();
            this.reject(new Error('Scanner cancelled.'));
        }

        _teardown() {
            this._loopActive = false;
            clearTimeout(this._hintTimer);
            document.removeEventListener('keydown', this._onKey);
            if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
            if (this.overlay) {
                this.overlay.classList.remove('is-visible');
                setTimeout(() => { this.overlay?.remove(); this.overlay = null; }, 280);
            }
        }

        _cycleHints() {
            const show = (i) => {
                this.hintEl.classList.remove('is-visible');
                this._hintTimer = setTimeout(() => {
                    const h = HINTS[i % HINTS.length];
                    this.hintEl.innerHTML = `<span class="ids-hint-icon">${h.icon}</span> ${h.text}`;
                    this.hintEl.classList.add('is-visible');
                    this._hintTimer = setTimeout(() => show(i + 1), 3200);
                }, 300);
            };
            this._hintTimer = setTimeout(() => show(1), 2800);
        }

        _setStatus(msg, type) {
            this.statusTxt.textContent = msg;
            this.statusPill.className = `ids-status-pill status-${type || 'detecting'}`;
            const dotColors = { detecting: '#D4A94E', stable: '#90EE90', error: '#FF9999' };
            this.statusDot.style.background = dotColors[type] || '#D4A94E';
        }
    }

    /* ─── Public API ─────────────────────────────────────────────────────── */
    global.IDScanner = {
        /**
         * Open the GCash-style scanner modal.
         * @param {'front'|'back'} side
         * @returns {Promise<string>} base64 JPEG data URL of the cropped ID
         */
        openModal(side) {
            return new ScannerInstance(side || 'front').open();
        }
    };

}(window));
