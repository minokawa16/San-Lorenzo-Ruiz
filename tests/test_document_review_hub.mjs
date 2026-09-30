import {spawn} from 'node:child_process';
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
          tipText: tip?.textContent || '',
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
    assert(s.tipText.includes('TUGON Tip: Ensure all details, seals, and signatures on your documents are readable before submitting to ensure fast verification.'), 'Verbatim TUGON Tip guidance banner exists');
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

    // 4. Test Pre-flight Validation with Invalid Files (Oversized > 5 MB & Unsupported MIME)
    console.log('\nTesting client-side pre-flight validation (size & MIME ceiling)...');
    const testInvalidFiles = await send('Runtime.evaluate', {
      returnByValue: true,
      expression: `(()=>{
        const fileInput = document.getElementById('requirementFileInput');
        const dt = new DataTransfer();
        
        // 1. Oversized file (6.2 MB > 5 MB)
        const hugeData = new Uint8Array(6.2 * 1024 * 1024);
        const hugeFile = new File([hugeData], 'oversized_document.jpg', { type: 'image/jpeg', lastModified: 2001 });
        
        // 2. Unsupported file (.docx)
        const docxData = new Uint8Array(100 * 1024);
        const docxFile = new File([docxData], 'unsupported_file.docx', { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', lastModified: 2002 });
        
        dt.items.add(hugeFile);
        dt.items.add(docxFile);
        
        fileInput.files = dt.files;
        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        
        const grid = document.getElementById('selectedFilesGrid');
        const invalidCards = grid.querySelectorAll('.doc-card.is-invalid');
        const alertBox = document.getElementById('uploadValidationAlert');
        const submitBtn = document.getElementById('submitRequestBtn');
        
        const badges = Array.from(invalidCards).map(c => c.querySelector('.doc-status-badge')?.textContent.trim());
        
        return {
          invalidCardCount: invalidCards.length,
          alertVisible: window.getComputedStyle(alertBox).display !== 'none',
          submitDisabled: submitBtn?.disabled === true,
          badges: badges
        };
      })()`
    });
    const invResult = testInvalidFiles.result.value;
    assert(invResult.invalidCardCount === 2, 'Invalid cards rendered with .is-invalid class');
    assert(invResult.badges.some(b => b.includes('Exceeds 5 MB')), 'Oversized file card has "Exceeds 5 MB" badge');
    assert(invResult.badges.some(b => b.includes('Invalid Format')), 'Unsupported file card has "Invalid Format" badge');
    assert(invResult.alertVisible, 'Inline error alert (#uploadValidationAlert) is visible');
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
