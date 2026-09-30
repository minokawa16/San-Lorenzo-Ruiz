import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const profile = mkdtempSync(join(tmpdir(), 'tugon-cert-chrome-'));
const port = 9338;
const browser = spawn(chrome, [
  `--remote-debugging-port=${port}`,
  '--headless=new',
  '--disable-gpu',
  '--no-first-run',
  '--no-default-browser-check',
  `--user-data-dir=${profile}`,
  'about:blank'
], { stdio: 'ignore' });

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
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params }));
    setTimeout(() => {
      if (pending.has(id)) {
        pending.delete(id);
        reject(new Error('CDP timeout: ' + method));
      }
    }, 10000);
  });
}

const consoleErrors = [];
let passCount = 0;
let failCount = 0;

function report(ok, msg) {
  if (ok) {
    console.log(`[PASS] ${msg}`);
    passCount++;
  } else {
    console.error(`[FAIL] ${msg}`);
    failCount++;
  }
}

try {
  const target = await getJson(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' });
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    ws.onopen = resolve;
    ws.onerror = reject;
  });

  ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
      consoleErrors.push(m.params.args.map(a => a.value || a.description).join(' '));
    }
    if (m.method === 'Runtime.exceptionThrown') {
      consoleErrors.push(m.params.exceptionDetails.text);
    }
    if (m.id && pending.has(m.id)) {
      const p = pending.get(m.id);
      pending.delete(m.id);
      m.error ? p.reject(new Error(m.error.message)) : p.resolve(m.result);
    }
  };

  await send('Page.enable');
  await send('Runtime.enable');
  await send('Network.enable');

  // Test 1: Staff Generate Certificates page (admin/certificate-generator.php)
  console.log('\n=== Testing Staff Generate Certificates Page (admin/certificate-generator.php) ===');
  await send('Network.setCookie', {
    name: 'TUGONSESSID',
    value: 'phase11admin',
    url: 'http://127.0.0.1:8099/',
    path: '/'
  });

  for (const width of [1440, 768, 375]) {
    await send('Emulation.setDeviceMetricsOverride', {
      width,
      height: 900,
      deviceScaleFactor: 1,
      mobile: width < 768
    });
    await send('Page.navigate', { url: 'http://127.0.0.1:8099/admin/certificate-generator.php' });

    // Wait for page ready
    for (let r = 0; r < 50; r++) {
      await pause(100);
      const res = await send('Runtime.evaluate', {
        returnByValue: true,
        expression: `document.readyState === 'complete'`
      });
      if (res.result.value) break;
    }

    const check = await send('Runtime.evaluate', {
      returnByValue: true,
      expression: `(() => {
        const bodyText = document.body.innerText;
        const hasOld1 = bodyText.includes('Official Sacramental Certificates');
        const hasOld2 = bodyText.includes('Sacramental Certifications');
        const hasBadge1 = bodyText.includes('REGISTRY EXTRACT');
        const hasBadge2 = bodyText.includes('CANONICAL CERTIFICATE');

        const headers = Array.from(document.querySelectorAll('.pds-cert-section-header')).map(h => ({
          title: h.querySelector('.pds-cert-section-title')?.innerText.trim(),
          desc: h.querySelector('.pds-cert-section-desc')?.innerText.trim()
        }));

        const cards = Array.from(document.querySelectorAll('.pds-cert-card')).map(c => {
          const rect = c.getBoundingClientRect();
          const pRect = c.parentElement.getBoundingClientRect();
          const title = c.querySelector('.pds-cert-title')?.innerText.trim();
          return { title, right: rect.right, left: rect.left, width: rect.width, parentRight: pRect.right };
        });

        const overflow = document.documentElement.scrollWidth - document.documentElement.clientWidth;
        const windowWidth = window.innerWidth;
        const funeralCard = cards.find(c => c.title === 'Funeral Certification');
        const funeralClipped = funeralCard ? (windowWidth >= 768 ? (funeralCard.right > windowWidth + 5) : (overflow > 2)) : false;

        return {
          width: window.innerWidth,
          overflow,
          hasOld1, hasOld2, hasBadge1, hasBadge2,
          headers,
          cardCount: cards.length,
          funeralClipped,
          funeralCardWidth: funeralCard ? funeralCard.width : 0
        };
      })()`
    });

    const v = check.result.value;
    report(v.overflow <= 2, `Width ${width}px: No horizontal page overflow (overflow=${v.overflow}px)`);
    report(!v.funeralClipped, `Width ${width}px: Funeral Certification card is NOT clipped on right`);
    report(v.cardCount === 8, `Width ${width}px: All 8 certificate cards rendered`);
    report(!v.hasOld1 && !v.hasOld2 && !v.hasBadge1 && !v.hasBadge2, `Width ${width}px: Zero old labels/badges in UI`);
    if (width === 1440) {
      report(v.headers[0]?.title.includes('Original Certificate'), 'Original Certificate section header title correct');
      report(v.headers[1]?.title.includes('Certification'), 'Certification section header title correct');
      report(v.headers[0]?.desc.length > 20, 'Original Certificate section description rendered');
      report(v.headers[1]?.desc.length > 20, 'Certification section description rendered');
    }
  }

  // Test 2: Parishioner Certificate Request Page (users/request-certificate.php)
  console.log('\n=== Testing Parishioner Certificate Request Form (users/request-certificate.php) ===');
  await send('Network.setCookie', {
    name: 'TUGONSESSID',
    value: 'phase11user',
    url: 'http://127.0.0.1:8099/',
    path: '/'
  });

  for (const width of [1440, 768, 375]) {
    await send('Emulation.setDeviceMetricsOverride', {
      width,
      height: 900,
      deviceScaleFactor: 1,
      mobile: width < 768
    });
    await send('Page.navigate', { url: 'http://127.0.0.1:8099/users/request-certificate.php' });

    for (let r = 0; r < 50; r++) {
      await pause(100);
      const res = await send('Runtime.evaluate', {
        returnByValue: true,
        expression: `document.readyState === 'complete'`
      });
      if (res.result.value) break;
    }

    const check = await send('Runtime.evaluate', {
      returnByValue: true,
      expression: `(() => {
        const bodyText = document.body.innerText;
        const hasOld1 = bodyText.includes('Official Sacramental Certificates');
        const hasOld2 = bodyText.includes('Sacramental Certifications');
        const hasBadge1 = bodyText.includes('REGISTRY EXTRACT');
        const hasBadge2 = bodyText.includes('CANONICAL CERTIFICATE');

        const cards = Array.from(document.querySelectorAll('.category-choice-card'));
        const rects = cards.map(c => c.getBoundingClientRect());
        const overflow = document.documentElement.scrollWidth - document.documentElement.clientWidth;

        const isMobile = window.innerWidth <= 768;
        // In desktop (1440), cards are side by side; equal height check
        const equalHeight = Math.abs(rects[0].height - rects[1].height) <= 3;
        // In mobile (375), cards should stack vertically (rects[1].top >= rects[0].bottom - 2)
        const stacked = rects[1].top >= (rects[0].bottom - 4);

        // Check touch targets >= 44px
        const interactiveElements = Array.from(document.querySelectorAll('.category-choice-card, .category-action-btn, .btn-change-category, .submit-request-btn, .form-control, .form-select'));
        const smallTouchTargets = interactiveElements.filter(el => {
          const r = el.getBoundingClientRect();
          return r.width > 0 && r.height > 0 && (r.height < 43.5 && r.width < 43.5);
        }).map(el => el.className + ' h=' + el.getBoundingClientRect().height);

        // Accessibility attributes
        const radioAttrs = cards.map(c => ({
          role: c.getAttribute('role'),
          ariaChecked: c.getAttribute('aria-checked'),
          tabIndex: c.tabIndex
        }));

        return {
          width: window.innerWidth,
          overflow,
          hasOld1, hasOld2, hasBadge1, hasBadge2,
          cardCount: cards.length,
          equalHeight,
          stacked,
          smallTouchTargetsCount: smallTouchTargets.length,
          smallTouchTargets,
          radioAttrs
        };
      })()`
    });

    const v = check.result.value;
    report(v.overflow <= 2, `Width ${width}px: No horizontal page overflow (overflow=${v.overflow}px)`);
    report(v.cardCount === 2, `Width ${width}px: Exactly 2 category cards present`);
    report(!v.hasOld1 && !v.hasOld2 && !v.hasBadge1 && !v.hasBadge2, `Width ${width}px: Zero old labels/badges`);

    if (width === 1440) {
      report(v.equalHeight, `Width ${width}px: Category cards have equal height`);
      report(v.radioAttrs.every(a => a.role === 'radio'), 'Category cards have role="radio"');
    }

    if (width === 375) {
      report(v.stacked, `Width ${width}px: Category cards stack vertically on mobile`);
      report(v.smallTouchTargetsCount === 0, `Width ${width}px: All interactive targets >= 44px (small count: ${v.smallTouchTargetsCount})`);
    }
  }

  // Test 3: Interactive category card selection and transition
  console.log('\n=== Testing Interactive Category Selection, Keyboard Navigation, and Type Reveal ===');
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: 'http://127.0.0.1:8099/users/request-certificate.php' });
  await pause(400);

  const interactionCheck = await send('Runtime.evaluate', {
    returnByValue: true,
    expression: `(() => {
      const cards = document.querySelectorAll('.category-choice-card');
      const certCard = cards[0]; // Certification
      const origCard = cards[1]; // Original Certificate

      // Click Certification card
      certCard.click();
      const cert1Selected = certCard.classList.contains('is-selected');
      const orig1Selected = origCard.classList.contains('is-selected');
      const groupCertDisp = document.querySelector('.cert-group-block[data-category="certification"]').style.display;
      const groupOrigDisp = document.querySelector('.cert-group-block[data-category="certificate"]').style.display;

      // Click Original Certificate card
      origCard.click();
      const cert2Selected = certCard.classList.contains('is-selected');
      const orig2Selected = origCard.classList.contains('is-selected');
      const groupCertDisp2 = document.querySelector('.cert-group-block[data-category="certification"]').style.display;
      const groupOrigDisp2 = document.querySelector('.cert-group-block[data-category="certificate"]').style.display;

      // Check Purpose Section visibility and step numbers
      const purposeVisible = document.getElementById('stepPurposeSection').style.display !== 'none';
      const uploadStepNum = document.getElementById('stepUploadNumber').innerText.trim();
      const paymentStepNum = document.getElementById('stepPaymentNumber').innerText.trim();

      return {
        step1: { cert1Selected, orig1Selected, groupCertDisp, groupOrigDisp },
        step2: { cert2Selected, orig2Selected, groupCertDisp2, groupOrigDisp2 },
        originalFlow: { purposeVisible, uploadStepNum, paymentStepNum }
      };
    })()`
  });

  const iv = interactionCheck.result.value;
  report(iv.step1.cert1Selected && !iv.step1.orig1Selected, 'Selecting Certification card marks it selected and deselects Original');
  report(iv.step1.groupCertDisp !== 'none' && iv.step1.groupOrigDisp === 'none', 'Selecting Certification reveals Certification types smoothly');
  report(!iv.step2.cert2Selected && iv.step2.orig2Selected, 'Selecting Original Certificate card marks it selected and deselects Certification (single selection)');
  report(iv.step2.groupCertDisp2 === 'none' && iv.step2.groupOrigDisp2 !== 'none', 'Selecting Original Certificate reveals Original types smoothly');
  report(!iv.originalFlow.purposeVisible, 'Purpose step is hidden for Original Certificate flow');
  report(iv.originalFlow.uploadStepNum === '3', 'Upload step renumbered to 3 for Original Certificate');
  report(iv.originalFlow.paymentStepNum === '4', 'Payment step renumbered to 4 for Original Certificate');

  // Test 4: Keyboard Navigation between radio cards
  const keyboardCheck = await send('Runtime.evaluate', {
    returnByValue: true,
    expression: `(() => {
      const cards = document.querySelectorAll('.category-choice-card');
      cards[0].focus();
      const initialFocused = document.activeElement === cards[0];

      // Dispatch ArrowRight
      cards[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
      const afterRightFocused = document.activeElement === cards[1];
      const afterRightSelected = cards[1].classList.contains('is-selected');

      // Dispatch ArrowLeft
      cards[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
      const afterLeftFocused = document.activeElement === cards[0];
      const afterLeftSelected = cards[0].classList.contains('is-selected');

      return {
        initialFocused,
        afterRightFocused,
        afterRightSelected,
        afterLeftFocused,
        afterLeftSelected
      };
    })()`
  });

  const kv = keyboardCheck.result.value;
  report(kv.initialFocused, 'Card 0 focused initially');
  report(kv.afterRightFocused && kv.afterRightSelected, 'ArrowRight focuses and selects Card 1 (Original Certificate)');
  report(kv.afterLeftFocused && kv.afterLeftSelected, 'ArrowLeft focuses and selects Card 0 (Certification)');

  // Test 5: Searchable Selector sync
  const selectorCheck = await send('Runtime.evaluate', {
    returnByValue: true,
    expression: `(() => {
      const select = document.getElementById('certificateSearchSelect');
      const mobileSelect = document.getElementById('certificateMobileSelect');

      // Set input to "Confirmation Certificate"
      select.value = 'Confirmation Certificate';
      select.dispatchEvent(new Event('input', { bubbles: true }));

      const activeRadio = document.querySelector('input[name="request_type"]:checked');
      const origCardSelected = document.querySelector('.category-choice-card[data-category="certificate"]').classList.contains('is-selected');
      const mobileVal = mobileSelect ? mobileSelect.value : '';

      return {
        radioVal: activeRadio ? activeRadio.value : '',
        origCardSelected,
        mobileVal
      };
    })()`
  });

  const sv = selectorCheck.result.value;
  report(sv.radioVal === 'confirmation_certificate', 'Searchable selector correctly selects confirmation_certificate radio');
  report(sv.origCardSelected, 'Searchable selector auto-switches active category card to Original Certificate');
  report(sv.mobileVal === 'confirmation_certificate', 'Mobile select synced with searchable selector');

  // Check console errors
  report(consoleErrors.length === 0, `Zero browser console errors (errors=${consoleErrors.length})`);
  if (consoleErrors.length > 0) {
    console.error('Console errors:', consoleErrors);
  }

} catch (err) {
  console.error('Test error:', err);
  failCount++;
} finally {
  if (ws) ws.close();
  browser.kill();
  try { rmSync(profile, { recursive: true, force: true }); } catch {}
  console.log(`\n========================================`);
  console.log(`RESULTS: ${passCount} passed, ${failCount} failed.`);
  console.log(`========================================\n`);
  process.exit(failCount > 0 ? 1 : 0);
}
