/**
 * E-REQUEST PARISH MANAGEMENT SYSTEM
 * Reusable UI Components Library
 * Handles: Toasts, Modals, Notifications, Dialogs, etc.
 */

// ============================================================
// TOAST NOTIFICATIONS
// ============================================================

class Toast {
  constructor(message, type = 'info', duration = 5000) {
    this.message = message;
    this.type = type; // 'success', 'error', 'warning', 'info'
    this.duration = duration;
    this.element = null;
  }

  show() {
    this.createElement();
    document.body.appendChild(this.element);
    
    // Trigger animation
    setTimeout(() => this.element.classList.add('show'), 10);
    
    // Auto-hide after duration
    if (this.duration > 0) {
      setTimeout(() => this.hide(), this.duration);
    }
  }

  hide() {
    this.element.classList.remove('show');
    setTimeout(() => this.element.remove(), 300);
  }

  createElement() {
    this.element = document.createElement('div');
    this.element.className = `toast toast-${this.type}`;
    this.element.innerHTML = `
      <div class="toast-content">
        <span class="toast-message">${this.message}</span>
        <button class="toast-close" onclick="this.parentElement.parentElement.remove()">×</button>
      </div>
    `;
  }

  static success(message) {
    new Toast(message, 'success').show();
  }

  static error(message) {
    new Toast(message, 'error').show();
  }

  static warning(message) {
    new Toast(message, 'warning').show();
  }

  static info(message) {
    new Toast(message, 'info').show();
  }
}

// ============================================================
// MODAL DIALOG
// ============================================================

class Modal {
  constructor(title, content, options = {}) {
    this.title = title;
    this.content = content;
    this.options = {
      size: 'md', // 'sm', 'md', 'lg', 'xl'
      confirmText: 'Confirm',
      cancelText: 'Cancel',
      onConfirm: () => {},
      onCancel: () => {},
      showCancel: true,
      ...options
    };
    this.element = null;
  }

  show() {
    this.createElement();
    document.body.appendChild(this.element);
    setTimeout(() => this.element.classList.add('active'), 10);
  }

  hide() {
    this.element.classList.remove('active');
    setTimeout(() => this.element.remove(), 300);
  }

  createElement() {
    const sizeClass = {
      'sm': 'modal-sm',
      'md': 'modal-md',
      'lg': 'modal-lg',
      'xl': 'modal-xl'
    }[this.options.size] || 'modal-md';

    this.element = document.createElement('div');
    this.element.className = `modal ${sizeClass}`;
    
    let footerButtons = `
      <button class="btn btn-primary" onclick="window.currentModal.confirm()">
        ${this.options.confirmText}
      </button>
    `;
    
    if (this.options.showCancel) {
      footerButtons += `
        <button class="btn btn-secondary" onclick="window.currentModal.cancel()">
          ${this.options.cancelText}
        </button>
      `;
    }

    this.element.innerHTML = `
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">${this.title}</h5>
          <button class="modal-close" onclick="window.currentModal.hide()">×</button>
        </div>
        <div class="modal-body">
          ${this.content}
        </div>
        <div class="modal-footer">
          ${footerButtons}
        </div>
      </div>
    `;

    window.currentModal = this;
  }

  confirm() {
    this.options.onConfirm();
    this.hide();
  }

  cancel() {
    this.options.onCancel();
    this.hide();
  }

  static confirm(message, onConfirm, onCancel) {
    return new Modal('Confirm', message, {
      confirmText: 'Yes',
      cancelText: 'No',
      onConfirm: onConfirm,
      onCancel: onCancel
    }).show();
  }

  static alert(title, message) {
    return new Modal(title, message, {
      showCancel: false,
      confirmText: 'Close'
    }).show();
  }
}

// ============================================================
// LOADING INDICATOR
// ============================================================

class LoadingIndicator {
  constructor(element) {
    this.element = typeof element === 'string' ? 
      document.querySelector(element) : element;
  }

  show() {
    if (!this.element) return;
    this.element.innerHTML = '<div class="spinner"></div>';
    this.element.classList.add('loading');
  }

  hide() {
    if (!this.element) return;
    this.element.innerHTML = '';
    this.element.classList.remove('loading');
  }

  static show(element) {
    new LoadingIndicator(element).show();
  }

  static hide(element) {
    new LoadingIndicator(element).hide();
  }
}

// ============================================================
// FORM VALIDATION
// ============================================================

class FormValidator {
  constructor(formElement) {
    this.form = typeof formElement === 'string' ? 
      document.querySelector(formElement) : formElement;
    this.errors = {};
  }

  validate() {
    this.errors = {};
    const inputs = this.form.querySelectorAll('[data-validate]');
    
    inputs.forEach(input => {
      const rules = input.dataset.validate.split('|');
      let value = input.value.trim();
      
      rules.forEach(rule => {
        if (rule === 'required' && !value) {
          this.addError(input, 'This field is required');
        }
        else if (rule === 'email' && value && !this.isEmail(value)) {
          this.addError(input, 'Please enter a valid email');
        }
        else if (rule === 'phone' && value && !this.isPhone(value)) {
          this.addError(input, 'Please enter a valid phone number');
        }
        else if (rule === 'min:5' && value.length < 5) {
          this.addError(input, 'Must be at least 5 characters');
        }
        else if (rule === 'password' && value && value.length < 8) {
          this.addError(input, 'Password must be at least 8 characters');
        }
      });
    });
    
    return Object.keys(this.errors).length === 0;
  }

  addError(input, message) {
    const key = input.name;
    if (!this.errors[key]) this.errors[key] = [];
    this.errors[key].push(message);
    
    input.classList.add('is-invalid');
    this.showError(input, message);
  }

  showError(input, message) {
    let errorElement = input.nextElementSibling;
    if (!errorElement || !errorElement.classList.contains('invalid-feedback')) {
      errorElement = document.createElement('div');
      errorElement.className = 'invalid-feedback';
      input.parentNode.insertBefore(errorElement, input.nextSibling);
    }
    errorElement.textContent = message;
  }

  clearErrors() {
    const inputs = this.form.querySelectorAll('.is-invalid');
    inputs.forEach(input => {
      input.classList.remove('is-invalid');
      const errorElement = input.nextElementSibling;
      if (errorElement && errorElement.classList.contains('invalid-feedback')) {
        errorElement.remove();
      }
    });
  }

  isEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  isPhone(phone) {
    return /^[\d\s\-\+\(\)]+$/.test(phone) && phone.replace(/\D/g, '').length >= 10;
  }
}

// ============================================================
// API CLIENT
// ============================================================

class ApiClient {
  constructor(baseUrl = '/api') {
    this.baseUrl = baseUrl;
  }

  async request(endpoint, options = {}) {
    const method = options.method || 'GET';
    const headers = {
      'Content-Type': 'application/json',
      ...options.headers
    };

    const config = {
      method,
      headers,
      ...options
    };

    if (options.body && typeof options.body === 'object') {
      config.body = JSON.stringify(options.body);
    }

    try {
      const response = await fetch(`${this.baseUrl}${endpoint}`, config);
      
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      return await response.json();
    } catch (error) {
      console.error('API Error:', error);
      Toast.error(error.message);
      throw error;
    }
  }

  get(endpoint) {
    return this.request(endpoint, { method: 'GET' });
  }

  post(endpoint, data) {
    return this.request(endpoint, { method: 'POST', body: data });
  }

  put(endpoint, data) {
    return this.request(endpoint, { method: 'PUT', body: data });
  }

  delete(endpoint) {
    return this.request(endpoint, { method: 'DELETE' });
  }
}

// ============================================================
// PAGINATION
// ============================================================

class Paginator {
  constructor(totalItems, itemsPerPage = 10) {
    this.totalItems = totalItems;
    this.itemsPerPage = itemsPerPage;
    this.currentPage = 1;
    this.totalPages = Math.ceil(totalItems / itemsPerPage);
  }

  getCurrentItems(items) {
    const start = (this.currentPage - 1) * this.itemsPerPage;
    const end = start + this.itemsPerPage;
    return items.slice(start, end);
  }

  goToPage(page) {
    if (page >= 1 && page <= this.totalPages) {
      this.currentPage = page;
    }
  }

  nextPage() {
    this.goToPage(this.currentPage + 1);
  }

  prevPage() {
    this.goToPage(this.currentPage - 1);
  }

  renderButtons(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    let html = '<div class="pagination">';
    
    // Previous button
    if (this.currentPage > 1) {
      html += `<button class="page-btn" onclick="paginator.prevPage()">← Previous</button>`;
    }

    // Page numbers
    for (let i = 1; i <= this.totalPages; i++) {
      if (i === this.currentPage) {
        html += `<button class="page-btn active">${i}</button>`;
      } else {
        html += `<button class="page-btn" onclick="paginator.goToPage(${i})">${i}</button>`;
      }
    }

    // Next button
    if (this.currentPage < this.totalPages) {
      html += `<button class="page-btn" onclick="paginator.nextPage()">Next →</button>`;
    }

    html += '</div>';
    container.innerHTML = html;
  }
}

// ============================================================
// DATA TABLE HELPER
// ============================================================

class DataTable {
  constructor(tableElement, options = {}) {
    this.table = typeof tableElement === 'string' ? 
      document.querySelector(tableElement) : tableElement;
    this.options = {
      sortable: true,
      searchable: true,
      pageable: true,
      itemsPerPage: 10,
      ...options
    };
    this.data = [];
    this.init();
  }

  init() {
    if (this.options.searchable) {
      this.addSearchBox();
    }
    if (this.options.sortable) {
      this.addSortHandlers();
    }
  }

  addSearchBox() {
    const searchBox = document.createElement('input');
    searchBox.type = 'text';
    searchBox.className = 'form-control mb-3';
    searchBox.placeholder = 'Search...';
    searchBox.addEventListener('keyup', (e) => this.search(e.target.value));
    
    if (this.table.parentNode) {
      this.table.parentNode.insertBefore(searchBox, this.table);
    }
  }

  search(query) {
    const rows = this.table.querySelectorAll('tbody tr');
    rows.forEach(row => {
      const text = row.textContent.toLowerCase();
      row.style.display = text.includes(query.toLowerCase()) ? '' : 'none';
    });
  }

  addSortHandlers() {
    const headers = this.table.querySelectorAll('th');
    headers.forEach((header, index) => {
      header.style.cursor = 'pointer';
      header.addEventListener('click', () => this.sort(index));
    });
  }

  sort(columnIndex) {
    // Implementation for sorting
    console.log('Sort column:', columnIndex);
  }
}

// ============================================================
// STEP FORM WIZARD
// ============================================================

class FormWizard {
  constructor(containerSelector, steps) {
    this.container = document.querySelector(containerSelector);
    this.steps = steps;
    this.currentStep = 0;
    this.stepData = {};
    this.init();
  }

  init() {
    this.renderSteps();
    this.showStep(0);
  }

  renderSteps() {
    // Render step indicators
    const indicatorHTML = this.steps.map((step, index) => `
      <div class="step-indicator ${index === 0 ? 'active' : ''} ${index < this.currentStep ? 'completed' : ''}">
        <div class="step-number">${index + 1}</div>
        <div class="step-label">${step.label}</div>
      </div>
    `).join('');

    const indicators = document.createElement('div');
    indicators.className = 'step-indicators';
    indicators.innerHTML = indicatorHTML;
    
    this.container.insertBefore(indicators, this.container.firstChild);
  }

  showStep(index) {
    if (index < 0 || index >= this.steps.length) return;

    // Hide all steps
    this.container.querySelectorAll('.step-content').forEach(el => {
      el.classList.remove('active');
    });

    // Show current step
    const stepContent = this.container.querySelector(`[data-step="${index}"]`);
    if (stepContent) {
      stepContent.classList.add('active');
    }

    this.currentStep = index;
    this.updateIndicators();
  }

  updateIndicators() {
    this.container.querySelectorAll('.step-indicator').forEach((indicator, index) => {
      indicator.classList.remove('active', 'completed');
      if (index === this.currentStep) {
        indicator.classList.add('active');
      } else if (index < this.currentStep) {
        indicator.classList.add('completed');
      }
    });
  }

  nextStep() {
    if (this.currentStep < this.steps.length - 1) {
      this.showStep(this.currentStep + 1);
    }
  }

  prevStep() {
    if (this.currentStep > 0) {
      this.showStep(this.currentStep - 1);
    }
  }

  saveStepData(stepIndex, data) {
    this.stepData[stepIndex] = data;
  }

  getAllData() {
    return this.stepData;
  }
}

// ============================================================
// EXPORT ALL COMPONENTS
// ============================================================

window.Toast = Toast;
window.Modal = Modal;
window.LoadingIndicator = LoadingIndicator;
window.FormValidator = FormValidator;
window.ApiClient = ApiClient;
window.Paginator = Paginator;
window.DataTable = DataTable;
window.FormWizard = FormWizard;

console.log('✓ UI Components library loaded');
