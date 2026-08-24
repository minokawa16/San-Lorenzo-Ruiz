(function () {
  'use strict';
  const live = document.createElement('div');
  live.className = 'tugon-live-region'; live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite'); live.setAttribute('aria-atomic', 'true');
  document.body.appendChild(live);

  document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
    const prompt = form.getAttribute('data-confirm');
    if (prompt && !window.confirm(prompt)) { event.preventDefault(); return; }
    const button = form.querySelector('button[type="submit"],input[type="submit"]');
    if (button && !button.disabled) {
      button.disabled = true; button.setAttribute('aria-disabled', 'true');
      button.dataset.originalLabel = button.innerHTML || button.value;
      if (button.tagName === 'BUTTON') button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Processing...';
      live.textContent = 'Processing request.';
    }
  });

  document.querySelectorAll('input:not([type="hidden"]),select,textarea').forEach(function (field) {
    if (field.labels && field.labels.length) return;
    if (field.hasAttribute('aria-label') || field.hasAttribute('aria-labelledby')) return;
    const name = field.getAttribute('placeholder') || field.getAttribute('name') || field.getAttribute('type') || 'form field';
    field.setAttribute('aria-label', name.replace(/[_-]+/g, ' '));
  });
  document.querySelectorAll('img:not([alt])').forEach(function (img) {
    const hint = img.getAttribute('title') || img.getAttribute('data-name') || '';
    img.setAttribute('alt', hint || (img.classList.contains('logo') || /logo/i.test(img.src) ? 'Parish logo' : ''));
  });
  document.querySelectorAll('button:not([aria-label]):not([title])').forEach(function (button) {
    if ((button.textContent || '').trim() !== '') return;
    const icon = button.querySelector('i'); const classes = icon ? icon.className : '';
    const names = {trash:'Delete',archive:'Archive',close:'Close',times:'Close',pen:'Edit',download:'Download',upload:'Upload',eye:'View',bell:'Notifications',bars:'Open menu',chevron:'Toggle section'};
    const key = Object.keys(names).find(function (name) { return classes.indexOf(name) !== -1; });
    button.setAttribute('aria-label', key ? names[key] : 'Open action menu');
  });

  document.addEventListener('invalid', function (event) {
    const field = event.target; if (!(field instanceof HTMLElement)) return;
    let error = field.parentElement && field.parentElement.querySelector('.tugon-field-error');
    if (!error) { error = document.createElement('div'); error.className = 'tugon-field-error text-danger small mt-1'; if (field.parentElement) field.parentElement.appendChild(error); }
    const id = field.id || ('field-' + Math.random().toString(16).slice(2)); field.id = id; error.id = id + '-error'; error.textContent = field.validationMessage || 'Please check this field.'; field.setAttribute('aria-describedby', error.id); field.setAttribute('aria-invalid', 'true'); live.textContent = 'Please correct the highlighted field.';
  }, true);
  document.addEventListener('input', function (event) { const field=event.target;if(field instanceof HTMLElement&&field.getAttribute('aria-invalid')==='true'&&field.checkValidity()){field.removeAttribute('aria-invalid');const error=document.getElementById(field.id+'-error');if(error)error.remove();} });

  let modalOpener = null;
  document.addEventListener('show.bs.modal', function (event) { modalOpener = event.relatedTarget || document.activeElement; });
  document.addEventListener('shown.bs.modal', function (event) {
    const modal = event.target; const focusable = modal.querySelector('[autofocus],button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled])');
    if (focusable) focusable.focus();
  });
  document.addEventListener('hidden.bs.modal', function () { if (modalOpener && typeof modalOpener.focus === 'function') modalOpener.focus(); modalOpener = null; });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Tab') return; const modal=document.querySelector('.modal.show,[role="dialog"][aria-modal="true"]'); if(!modal)return;
    const items=Array.from(modal.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')).filter(function(el){return el.offsetParent!==null;});
    if(!items.length)return;const first=items[0],last=items[items.length-1];if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  });
})();
