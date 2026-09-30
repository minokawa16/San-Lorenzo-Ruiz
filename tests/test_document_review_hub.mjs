import {spawn, execSync} from 'node:child_process';
import {mkdtempSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

const chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const profile = mkdtempSync(join(tmpdir(), 'tugon-doc-hub-'));
const port = 9338;
const browser = spawn(chrome, [
  `--remote-debugging-port=${port}`,
  '--headless=new',
  '--disable-gpu',
  '--no-first-run',
  '--no-default-browser-check',
  `--user-data-dir=${profile}`,
  'about:blank'
], {stdio: 'ignore'});

const pause = ms => new Promise(r => setTimeout(r, ms));

async function getJson(url, options) {
  for (let i = 0; i < 30; i++) {
    try {
      const r = await fetch(url, options);
      if (r.ok) return r.json();
    } catch {}
    await pause(200);
  }
  throw new Error('Chrome DevTools endpoint unavailable');
}

let seq = 0;
const pending = new Map();
let ws;

function send(method, params = {}) {
  return new Promise((resolve, reject) => {
    const id = ++seq;
    pending.set(id, {resolve, reject});
    ws.send(JSON.stringify({id, method, params}));
    setTimeout(() => {
      if (pending.has(id)) {
        pending.delete(id);
        reject(new Error('CDP timeout: ' + method));
      }
    }, 15000);
  });
}

async function run() {
  console.log('=== Starting Parishioner Document Review E2E Tests ===\n');
  try {
    execSync('php tests/setup_responsive_sessions.php', {stdio: 'ignore'});
  } catch {}
  let passCount = 0;
  let failCount = 0;

  function assert(condition, name) {
    if (condition) {
      console.log(`[PASS] ${name}`);
      passCount++;
    } else {
      console.error(`[FAIL] ${name}`);
      failCount++;
    }
  }

  try {
    const target = await getJson(`http://127.0.0.1:${port}/json/new?about:blank`, {method: 'PUT'});
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
    ws.onmessage = e => {
      const m = JSON.parse(e.data);
      if (m.id && pending.has(m.id)) {
        const p = pending.get(m.id);
        pending.delete(m.id);
        m.error ? p.reject(new Error(m.error.message)) : p.resolve(m.result);
      }
    };

    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');

    // Authenticate with test fixture session
    await send('Network.setCookie', {
      name: 'TUGONSESSID',
      value: 'phase11user',
      url: 'http://127.0.0.1:8099/',
      path: '/'
    });

    await send('Emulation.setDeviceMetricsOverride', {
      width: 1280,
      height: 900,
      deviceScaleFactor: 1,
      mobile: false
    });

    console.log('Navigating to http://127.0.0.1:8099/users/request-certificate.php...');
    await send('Page.navigate', {url: 'http://127.0.0.1:8099/users/request-certificate.php'});

    // Wait for page to be ready
    let ready = false;
    let lastState = null;
    for (let i = 0; i < 40; i++) {
      await pause(150);
      const state = await send('Runtime.evaluate', {
        returnByValue: true,
        expression: `({
          url: location.href,
          title: document.title,
          ready: document.readyState,
          hasUpload: !!document.getElementById('stepUploadSection')
        })`
      });
      lastState = state.result.value;
      if (lastState.ready === 'complete' && lastState.hasUpload) {
        ready = true;
        break;
      }
    }
    console.log('Last State:', JSON.stringify(lastState));
    assert(ready, 'request-certificate.php loaded successfully');

    // 1. Structure Verification
    const structure = await send('Runtime.evaluate', {
      returnByValue: true,
      expression: `(()=>{
        const section = document.getElementById('stepUploadSection');
        const zone = document.getElementById('uploadZone');
        const input = document.getElementById('requirementFileInput');
        const tip = document.getElementById('tugonUploadTip');
        const alertBox = document.getElementById('uploadValidationAlert');
        const hub = document.getElementById('filePreviewHub');
        const grid = document.getElementById('selectedFilesGrid');
        const modal = document.getElementById('documentPreviewModal');
        return {
          hasSection: !!section,
          hasZone: !!zone,
          hasInput: !!input && input.multiple && input.name === 'requirement_files[]',
          inputAccept: input?.getAttribute('accept') || '',
          hasTip: !!tip,
          hasAlertBox: !!alertBox,
          hasHub: !!hub,
          hasGrid: !!grid,
          hasModal: !!modal
        };
      })()`
    });
    const s = structure.result.value;
    assert(s.hasSection, 'Step 4 Upload Section (#stepUploadSection) exists');
    assert(s.hasZone, 'Dropzone (#uploadZone) exists');
    assert(s.hasInput, 'File input #requirementFileInput[type="file"][multiple] name="requirement_files[]" exists');
    assert(s.inputAccept.includes('.pdf') && s.inputAccept.includes('.png'), 'Input has strict accept attribute');
    assert(!s.hasTip, 'TUGON Tip guidance banner (#tugonUploadTip) is removed');
    assert(s.hasAlertBox, 'Validation alert (#uploadValidationAlert) exists');
    assert(s.hasHub, 'Aggregate summary bar (#filePreviewHub) exists');
    assert(s.hasGrid, 'Document cards container (#selectedFilesGrid) exists');
    assert(s.hasModal, 'Inspection Lightbox Modal (#documentPreviewModal) exists');

    // 2. Simulate Uploading 3 Valid Files (JPG, PNG, PDF)
    console.log('\nTesting valid files upload and DataTransfer queue...');
    const addValidFiles = await send('Runtime.evaluate', {
      returnByValue: true,
      expression: `(()=>{
        const fileInput = document.getElementById('requirementFileInput');
        const dt = new DataTransfer();
        
        // 1. Valid JPG (1.5 MB)
        const jpgData = new Uint8Array(1.5 * 1024 * 1024);
        const jpgFile = new File([jpgData], 'psa_birth_certificate.jpg', { type: 'image/jpeg', lastModified: 1001 });
        
        // 2. Valid PNG (800 KB)
        const pngData = new Uint8Array(800 * 1024);
        const pngFile = new File([pngData], 'government_valid_id.png', { type: 'image/png', lastModified: 1002 });
        
        // 3. Valid PDF (2.2 MB)
        const pdfData = new Uint8Array(2.2 * 1024 * 1024);
        const pdfFile = new File([pdfData], 'baptismal_record_extract.pdf', { type: 'application/pdf', lastModified: 1003 });
        
        dt.items.add(jpgFile);
        dt.items.add(pngFile);
        dt.items.add(pdfFile);
        
        fileInput.files = dt.files;
        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        
        const grid = document.getElementById('selectedFilesGrid');
        const cards = grid.querySelectorAll('.doc-card');
        const hubTitle = document.getElementById('aggregateCount')?.textContent || '';
        const hubSize = document.getElementById('aggregateSize')?.textContent || '';
        
        return {
          cardCount: cards.length,
          allValid: Array.from(cards).every(c => c.classList.contains('is-valid')),
          hasThumb: !!cards[0]?.querySelector('.doc-card-thumbnail'),
          hasPdfPlaceholder: !!cards[2]?.querySelector('.doc-card-pdf-placeholder'),
          hubTitle: hubTitle,
          hubSize: hubSize,
          syncedInputFiles: fileInput.files.length
        };
      })()`
    });
    const vResult = addValidFiles.result.value;
    assert(vResult.cardCount === 3, '3 document cards rendered in #selectedFilesGrid');
    assert(vResult.allValid, 'All 3 cards have .is-valid status');
    assert(vResult.hasThumb, 'Image card renders thumbnail');
    assert(vResult.hasPdfPlaceholder, 'PDF card renders styled PDF document placeholder');
    assert(vResult.hubTitle === '3 files selected', 'Aggregate count displays "3 files selected"');
    assert(vResult.syncedInputFiles === 3, 'Native fileInput.files is dynamically synced to 3 files');

    // 3. Test Individual File Removal
    console.log('\nTesting individual file removal and aggregate counter refresh...');
    const removeOne = await send('Runtime.evaluate', {
      returnByValue: true,
      expression: `(()=>{
        const grid = document.getElementById('selectedFilesGrid');
        const firstCard = grid.querySelector('.doc-card');
        const removeBtn = firstCard.querySelector('[data-action="remove"]');
        removeBtn.click();
        
        const fileInput = document.getElementById('requirementFileInput');
        const hubTitle = document.getElementById('aggregateCount')?.textContent || '';
        const hubSize = document.getElementById('aggregateSize')?.textContent || '';
        
        return {
          remainingCards: grid.querySelectorAll('.doc-card').length,
          syncedInputFiles: fileInput.files.length,
          hubTitle: hubTitle
        };
      })()`
    });
    const rResult = removeOne.result.value;
    assert(rResult.syncedInputFiles === 2, 'File removed from DataTransfer queue; input.files length is 2');
    assert(rResult.hubTitle === '2 files selected', 'Aggregate counter refreshed to "2 files selected"');

    // 4. Test Pre-flight Validation with Large File (> 10 MB) & Unsupported MIME (.docx)
    console.log('\nTesting client-side pre-flight validation (large file permitted, MIME restricted)...');
    const testInvalidFiles = await send('Runtime.evaluate', {
      returnByValue: true,
      expression: `(()=>{
        const fileInput = document.getElementById('requirementFileInput');
        const dt = new DataTransfer();
        
        // 1. Large high-res file (10.77 MB) - should be ALLOWED and VALID
        const hugeData = new Uint8Array(Math.round(10.77 * 1024 * 1024));
        const hugeFile = new File([hugeData], 'SHA01998.JPG', { type: 'image/jpeg', lastModified: 2001 });
        
        // 2. Unsupported file (.docx) - should be FLAGGED
        const docxData = new Uint8Array(100 * 1024);
        const docxFile = new File([docxData], 'unsupported_file.docx', { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', lastModified: 2002 });
        
        dt.items.add(hugeFile);
        dt.items.add(docxFile);
        
        fileInput.files = dt.files;
        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        
        const grid = document.getElementById('selectedFilesGrid');
        const cards = grid.querySelectorAll('.doc-card');
        const invalidCards = grid.querySelectorAll('.doc-card.is-invalid');
        const alertBox = document.getElementById('uploadValidationAlert');
        const submitBtn = document.getElementById('submitRequestBtn');
        
        const largeCard = Array.from(cards).find(c => c.textContent.includes('SHA01998.JPG'));
        const docxCard = Array.from(cards).find(c => c.textContent.includes('unsupported_file.docx'));
        
        const largeCardInspect = largeCard?.querySelector('.btn-inspect');
        const docxCardInspect = docxCard?.querySelector('.btn-inspect');

        return {
          invalidCardCount: invalidCards.length,
          largeCardValid: largeCard?.classList.contains('is-valid') === true,
          largeCardInspectEnabled: largeCardInspect && !largeCardInspect.disabled,
          largeCardBadge: largeCard?.querySelector('.doc-status-badge')?.textContent.trim() || '',
          docxCardBadge: docxCard?.querySelector('.doc-status-badge')?.textContent.trim() || '',
          docxCardInspectDisabled: docxCardInspect?.disabled === true,
          alertVisible: window.getComputedStyle(alertBox).display !== 'none',
          submitDisabled: submitBtn?.disabled === true
        };
      })()`
    });
    const invResult = testInvalidFiles.result.value;
    assert(invResult.largeCardValid, 'Large high-res file (10.77 MB) is VALID and has .is-valid class');
    assert(invResult.largeCardInspectEnabled, 'Large file "Inspect" button is ACTIVE and enabled');
    assert(invResult.largeCardBadge.includes('Verified'), 'Large file card has "Verified" badge (no "Exceeds 5 MB")');
    assert(invResult.docxCardBadge.includes('Invalid Format'), 'Unsupported file card has "Invalid Format" badge');
    assert(invResult.docxCardInspectDisabled, 'Unsupported file "Inspect" button is disabled');
    assert(invResult.invalidCardCount === 1, 'Only the unsupported .docx file is marked as invalid');
    assert(invResult.alertVisible, 'Inline error alert (#uploadValidationAlert) is visible due to invalid format');
    assert(invResult.submitDisabled, 'Master submit button (#submitRequestBtn) is disabled when invalid files exist');

    // 5. Remove Invalid Files -> Verify System Self-Heals
    console.log('\nTesting removal of invalid files restores master submit button...');
    const clearInvalids = await send('Runtime.evaluate', {
      returnByValue: true,
      expression: `(()=>{
        const grid = document.getElementById('selectedFilesGrid');
        const invalidCards = Array.from(grid.querySelectorAll('.doc-card.is-invalid'));
        invalidCards.forEach(c => {
          const btn = c.querySelector('[data-action="remove"]');
          btn?.click();
        });
        
        const alertBox = document.getElementById('uploadValidationAlert');
        const submitBtn = document.getElementById('submitRequestBtn');
        
        return {
          alertVisible: window.getComputedStyle(alertBox).display !== 'none',
          submitDisabled: submitBtn?.disabled === true
        };
      })()`
    });
    await pause(300); // wait for unmount animation
    const checkRemaining = await send('Runtime.evaluate', {
      returnByValue: true,
      expression: `document.querySelectorAll('#selectedFilesGrid .doc-card.is-invalid').length`
    });
    const clrResult = clearInvalids.result.value;
    assert(checkRemaining.result.value === 0, 'All invalid files removed');
    assert(!clrResult.alertVisible, 'Inline alert banner automatically dismissed');
    assert(!clrResult.submitDisabled, 'Master submit button re-enabled');

    // 6. Test Lightbox Modal Inspection (Zoom, Pan, Rotate)
    console.log('\nTesting Lightbox modal inspection, zoom, and rotation controls...');
    const testModal = await send('Runtime.evaluate', {
      returnByValue: true,
      expression: `(()=>{
        const grid = document.getElementById('selectedFilesGrid');
        const imageCard = grid.querySelector('.doc-card.is-valid');
        const inspectBtn = imageCard.querySelector('[data-action="inspect"]');
        inspectBtn.click();
        
        const modal = document.getElementById('documentPreviewModal');
        const label = document.getElementById('documentPreviewModalLabel')?.textContent || '';
        const meta = document.getElementById('docModalMeta')?.textContent || '';
        const img = document.getElementById('docModalImage');
        
        // Test Zoom In
        const zoomInBtn = document.getElementById('docZoomInBtn');
        zoomInBtn.click();
        const zoom1 = document.getElementById('docZoomLevel')?.textContent;
        const transform1 = img.style.transform;
        
        // Test Rotate
        const rotateBtn = document.getElementById('docRotateBtn');
        rotateBtn.click();
        const rotate1 = document.getElementById('docRotateAngle')?.textContent;
        const transform2 = img.style.transform;
        
        rotateBtn.click();
        const rotate2 = document.getElementById('docRotateAngle')?.textContent;
        
        // Test Reset Zoom
        const zoomResetBtn = document.getElementById('docZoomResetBtn');
        zoomResetBtn.click();
        const zoomReset = document.getElementById('docZoomLevel')?.textContent;
        
        return {
          label: label,
          meta: meta,
          zoom1: zoom1,
          transform1: transform1,
          rotate1: rotate1,
          rotate2: rotate2,
          transform2: transform2,
          zoomReset: zoomReset
        };
      })()`
    });
    const mResult = testModal.result.value;
    console.log('mResult debug:', JSON.stringify(mResult));
    assert(mResult.label.length > 0, 'Modal header displays document title');
    assert(mResult.meta.includes('bytes'), 'Modal header displays exact file byte size');
    assert(mResult.zoom1 === '125%', 'Zoom In button increases zoom to 125%');
    assert(mResult.rotate1 === '90°' && mResult.rotate2 === '180°', 'Rotate toggle advances from 0° -> 90° -> 180°');
    assert(mResult.zoomReset === '100%', 'Zoom reset returns zoom to 100%');

    // 7. Test Responsive Layout
    console.log('\nTesting responsive grid wrapping on desktop (1440px), tablet (768px), mobile (375px)...');
    for (const width of [1440, 768, 375]) {
      await send('Emulation.setDeviceMetricsOverride', {
        width,
        height: 800,
        deviceScaleFactor: 1,
        mobile: width < 768
      });
      await pause(100);
      const resp = await send('Runtime.evaluate', {
        returnByValue: true,
        expression: `(()=>{
          const grid = document.getElementById('selectedFilesGrid');
          const cards = grid.querySelectorAll('.doc-card');
          return {
            width: window.innerWidth,
            gridWidth: grid.offsetWidth,
            cardCount: cards.length
          };
        })()`
      });
      assert(resp.result.value.gridWidth > 0, `Grid container rendered cleanly at ${width}px`);
    }

    // 8. Test Exact User Scenario: 4 Large High-Resolution Camera Photos (42.50 MB Total)
    console.log('\nTesting exact user scenario: 4 large camera photos totaling 42.50 MB...');
    const userScenario = await send('Runtime.evaluate', {
      returnByValue: true,
      expression: `(()=>{
        const clearBtn = document.getElementById('clearAllUploadsBtn');
        if (clearBtn) clearBtn.click();

        const fileInput = document.getElementById('requirementFileInput');
        const dt = new DataTransfer();

        // 4 files matching user screenshot:
        // SHA01998 (1).JPG: 10.77 MB
        const f1 = new File([new Uint8Array(Math.round(10.77 * 1024 * 1024))], 'SHA01998 (1).JPG', { type: 'image/jpeg', lastModified: 3001 });
        // SHA01998.JPG: 10.77 MB
        const f2 = new File([new Uint8Array(Math.round(10.77 * 1024 * 1024))], 'SHA01998.JPG', { type: 'image/jpeg', lastModified: 3002 });
        // SHA01999.JPG: 10.71 MB
        const f3 = new File([new Uint8Array(Math.round(10.71 * 1024 * 1024))], 'SHA01999.JPG', { type: 'image/jpeg', lastModified: 3003 });
        // SHA02000.JPG: 10.24 MB
        const f4 = new File([new Uint8Array(Math.round(10.24 * 1024 * 1024))], 'SHA02000.JPG', { type: 'image/jpeg', lastModified: 3004 });

        dt.items.add(f1);
        dt.items.add(f2);
        dt.items.add(f3);
        dt.items.add(f4);

        fileInput.files = dt.files;
        fileInput.dispatchEvent(new Event('change', { bubbles: true }));

        const grid = document.getElementById('selectedFilesGrid');
        const cards = grid.querySelectorAll('.doc-card');
        const invalidCards = grid.querySelectorAll('.doc-card.is-invalid');
        const alertBox = document.getElementById('uploadValidationAlert');
        const submitBtn = document.getElementById('submitRequestBtn');
        const hubTitle = document.getElementById('aggregateCount')?.textContent || '';
        const hubSize = document.getElementById('aggregateSize')?.textContent || '';

        const allInspectEnabled = Array.from(cards).every(c => {
          const btn = c.querySelector('.btn-inspect');
          return btn && !btn.disabled;
        });

        const hasExceedsBadge = Array.from(cards).some(c => c.textContent.includes('Exceeds 5 MB'));

        return {
          cardCount: cards.length,
          invalidCount: invalidCards.length,
          allInspectEnabled: allInspectEnabled,
          hasExceedsBadge: hasExceedsBadge,
          alertHidden: window.getComputedStyle(alertBox).display === 'none',
          submitActive: submitBtn && !submitBtn.disabled,
          hubTitle: hubTitle,
          hubSize: hubSize
        };
      })()`
    });
    const uResult = userScenario.result.value;
    assert(uResult.cardCount === 4, '4 high-resolution cards rendered');
    assert(uResult.invalidCount === 0, '0 cards marked invalid (all 4 are valid)');
    assert(uResult.allInspectEnabled, 'All 4 large cards have active, clickable Inspect buttons');
    assert(!uResult.hasExceedsBadge, 'Zero "Exceeds 5 MB" warning badges present');
    assert(uResult.alertHidden, 'Validation warning banner is completely hidden');
    assert(uResult.submitActive, 'Master submit button is enabled for 42.50 MB submission');
    assert(uResult.hubTitle === '4 files selected', 'Aggregate count shows "4 files selected"');
    assert(uResult.hubSize.includes('42.49 MB') || uResult.hubSize.includes('42.50 MB'), `Aggregate total size matches ~42.50 MB (actual: ${uResult.hubSize})`);

  } catch (err) {
    console.error('Test execution failed with error:', err);
    failCount++;
  } finally {
    try { if (ws) ws.close(); } catch {}
    try { browser.kill(); } catch {}
    try { rmSync(profile, {recursive: true, force: true}); } catch {}
  }

  console.log(`\n=== Test Results: ${passCount} Passed, ${failCount} Failed ===`);
  if (failCount > 0) {
    process.exit(1);
  } else {
    console.log('ALL TESTS PASSED SUCCESSFULLY!');
    process.exit(0);
  }
}

run();
