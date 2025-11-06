'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const apiBase = './api.php';
  const params = new URLSearchParams(window.location.search);
  const clientCode = params.get('c');

  if (!clientCode) {
    window.location.href = 'index.php';
    return;
  }

  const apiUrl = `${apiBase}?c=${encodeURIComponent(clientCode)}`;
  let fullList = [];
  let pendingDeleteId = null;
  let intervalId = null;
  let csrfToken = null;
  let isProcessing = false;
  let appInitialized = false;
  const spinnerOverlay = document.getElementById('globalSpinner');

  function setLoading(state) {
    isProcessing = state;
    if (!spinnerOverlay) {
      return;
    }
    spinnerOverlay.classList.toggle('hidden', !state);
  }

  function updateCsrfToken(token) {
    if (!token) {
      return;
    }
    csrfToken = token;
    const uploadField = document.getElementById('csrfUpload');
    if (uploadField) {
      uploadField.value = token;
    }
  }

  function extractCsrf(payload) {
    if (payload && typeof payload === 'object' && payload._csrf) {
      updateCsrfToken(payload._csrf);
      delete payload._csrf;
    }
    return payload;
  }

  function startPolling(refreshFn) {
    stopPolling();
    intervalId = window.setInterval(refreshFn, 240000);
  }

  function stopPolling() {
    if (intervalId !== null) {
      window.clearInterval(intervalId);
    }
    intervalId = null;
  }

  function makeForm(data) {
    const formData = new URLSearchParams();
    if (csrfToken) {
      formData.append('_csrf', csrfToken);
    }
    Object.entries(data).forEach(([key, value]) => {
      if (value !== undefined && value !== null) {
        formData.append(key, value);
      }
    });
    return formData;
  }

  function appendCsrf(formData) {
    if (formData instanceof FormData && csrfToken && !formData.has('_csrf')) {
      formData.append('_csrf', csrfToken);
    }
  }

  async function fetchJson(url, options = {}, { showSpinner = false } = {}) {
    const requestOptions = Object.assign({ credentials: 'same-origin' }, options);
    if (showSpinner) {
      setLoading(true);
    }
    try {
      const response = await fetch(url, requestOptions);
      const data = await response.json();
      const payload = extractCsrf(data);
      if (!response.ok) {
        const message = payload && typeof payload === 'object' && payload.error ? payload.error : 'Error inesperado';
        throw new Error(message);
      }
      return payload;
    } catch (error) {
      console.error(error);
      toast(error.message === 'Failed to fetch' ? 'Se perdió la conexión con el servidor.' : error.message);
      throw error;
    } finally {
      if (showSpinner) {
        setLoading(false);
      }
    }
  }

  function toast(message, duration = 3000) {
    const container = document.getElementById('toast-container');
    if (!container) {
      return;
    }
    const element = document.createElement('div');
    element.className = 'toast';
    element.innerHTML = `<span>${message}</span><button onclick="this.parentElement.remove()">×</button>`;
    container.appendChild(element);
    window.setTimeout(() => {
      element.remove();
    }, duration);
  }

  function setButtonLoading(button, loading) {
    if (!button) {
      return;
    }
    if (loading) {
      button.dataset.originalText = button.textContent;
      button.textContent = 'Procesando…';
      button.disabled = true;
    } else {
      if (button.dataset.originalText) {
        button.textContent = button.dataset.originalText;
      }
      button.disabled = false;
    }
  }

  function confirmDialog(message) {
    return new Promise((resolve) => {
      const overlay = document.getElementById('confirmOverlay');
      if (!overlay) {
        resolve(false);
        return;
      }
      document.getElementById('confirmMsg').textContent = message;
      overlay.classList.remove('hidden');
      const confirmButton = document.getElementById('confirmOk');
      document.getElementById('confirmOk').onclick = () => {
        overlay.classList.add('hidden');
        resolve(true);
      };
      document.getElementById('confirmCancel').onclick = () => {
        overlay.classList.add('hidden');
        resolve(false);
      };
      if (confirmButton) {
        confirmButton.focus();
      }
    });
  }

  function clearSearchInternal() {
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('results-search');
    const alertBox = document.getElementById('search-alert');
    if (searchInput) {
      searchInput.value = '';
    }
    if (searchResults) {
      searchResults.innerHTML = '';
    }
    if (alertBox) {
      alertBox.innerText = '';
    }
  }

  window.clearSearch = clearSearchInternal;

  function initApp() {
    if (appInitialized) {
      return;
    }
    appInitialized = true;
    const deleteOverlay = document.getElementById('deleteOverlay');
    const deleteKeyInput = document.getElementById('deleteKeyInput');
    const deleteKeyError = document.getElementById('deleteKeyError');
    const tabs = document.querySelectorAll('.tab');
    const tabContents = document.querySelectorAll('.tab-content');
    const refreshButton = document.getElementById('refreshButton');

    window.openDeleteOverlay = (id) => {
      pendingDeleteId = id;
      deleteKeyError.classList.add('hidden');
      deleteKeyInput.value = '';
      deleteOverlay.classList.remove('hidden');
      deleteKeyInput.focus();
    };

    if (refreshButton) {
      refreshButton.addEventListener('click', () => {
        refresh();
      });
    }

    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') {
        return;
      }
      if (!deleteOverlay.classList.contains('hidden')) {
        deleteOverlay.classList.add('hidden');
        deleteKeyError.classList.add('hidden');
      }
      const confirmOverlay = document.getElementById('confirmOverlay');
      if (confirmOverlay && !confirmOverlay.classList.contains('hidden')) {
        confirmOverlay.classList.add('hidden');
      }
    });

    document.getElementById('deleteKeyOk').onclick = async () => {
      const key = deleteKeyInput.value.trim();
      if (!key) {
        deleteKeyError.classList.remove('hidden');
        deleteKeyInput.focus();
        return;
      }
      const confirmed = await confirmDialog('¿Eliminar este documento?');
      if (!confirmed) {
        return;
      }
      const success = await deleteDoc(pendingDeleteId, key);
      if (success) {
        deleteOverlay.classList.add('hidden');
        deleteKeyError.classList.add('hidden');
        deleteKeyInput.value = '';
      } else {
        deleteKeyError.classList.remove('hidden');
        deleteKeyInput.select();
      }
    };

    document.getElementById('deleteKeyCancel').onclick = () => {
      deleteOverlay.classList.add('hidden');
      deleteKeyError.classList.add('hidden');
      deleteKeyInput.value = '';
      pendingDeleteId = null;
    };

    async function refresh() {
      try {
        const payload = await fetchJson(`${apiUrl}&action=list&page=1&per_page=100`);
        fullList = payload.data || [];

        const activeTab = document.querySelector('.tab.active');
        if (activeTab && activeTab.dataset.tab === 'tab-list') {
          const term = document.getElementById('consultFilterInput').value.trim().toLowerCase();
          if (term) {
            doConsultFilter();
          } else {
            render(fullList, 'results-list', false);
          }
        }
      } catch (error) {
        console.error(error);
      }
    }

    tabs.forEach((tab) => {
      tab.onclick = () => {
        tabs.forEach((t) => t.classList.remove('active'));
        tabContents.forEach((content) => content.classList.add('hidden'));
        tab.classList.add('active');
        document.getElementById(tab.dataset.tab).classList.remove('hidden');
        if (tab.dataset.tab === 'tab-list') {
          refresh();
          startPolling(refresh);
        } else {
          stopPolling();
        }
        if (tab.dataset.tab === 'tab-search') {
          clearSearchInternal();
        }
        if (tab.dataset.tab === 'tab-code') {
          clearCode();
        }
      };
    });

    const activeTab = document.querySelector('.tab.active');
    if (activeTab) {
      activeTab.click();
    }

    function render(items, containerId, hideActions) {
      const container = document.getElementById(containerId);
      if (!container) {
        return;
      }
      if (!items || !items.length) {
        container.innerHTML = '<p class="text-gray-500">No hay documentos.</p>';
        return;
      }
      container.innerHTML = items
        .map((doc) => `
          <div class="border rounded p-4 bg-gray-50">
            <div class="flex justify-between">
              <div>
                <h3 class="font-semibold text-lg">${doc.name}</h3>
                <p class="text-gray-600">${doc.date}</p>
                <p class="text-gray-600 text-sm">Archivo PDF: ${doc.path}</p>
                <a href="download.php?c=${encodeURIComponent(clientCode)}&id=${doc.id}" target="_blank" class="text-indigo-600 underline">Ver PDF</a>
              </div>
              <div class="button-group text-right">
                ${!hideActions ? `
                  <button onclick="editDoc(${doc.id})" class="btn btn--warning px-2 py-1 text-lg">Editar</button>
                  <button onclick="openDeleteOverlay(${doc.id})" class="btn btn--primary px-2 py-1 text-lg">Eliminar</button>
                ` : ''}
                <button data-id="${doc.id}" onclick="toggleCodes(this)" class="btn btn--secondary px-2 py-1 text-lg">Ver Códigos</button>
              </div>
            </div>
            <pre id="codes${doc.id}" class="mt-2 p-2 bg-white rounded hidden">${(doc.codes || []).join('\n')}</pre>
          </div>
        `)
        .join('');
    }

    window.doSearch = async () => {
      const textarea = document.getElementById('searchInput');
      if (!textarea) {
        return;
      }
      const rawValue = textarea.value.trim();
      if (!rawValue) {
        return;
      }
      const codes = [...new Set(rawValue.split(/\r?\n/).map((line) => line.trim().split(/\s+/)[0]).filter(Boolean))];
      const body = new FormData();
      body.append('action', 'search');
      body.append('codes', codes.join('\n'));
      appendCsrf(body);
      let data;
      try {
        data = await fetchJson(apiUrl, { method: 'POST', body });
      } catch (error) {
        return;
      }
      const results = Array.isArray(data) ? data : [];
      const found = new Set(results.flatMap((item) => item.codes || []));
      const missing = codes.filter((code) => !found.has(code));
      const alertBox = document.getElementById('search-alert');
      const searchResults = document.getElementById('results-search');
      if (alertBox) {
        alertBox.innerText = missing.length ? 'No encontrados: ' + missing.join(', ') : '';
      }
      if (searchResults) {
        searchResults.innerHTML = '';
        render(results, 'results-search', true);
      }
    };

    const uploadForm = document.getElementById('form-upload');
    const fileInput = document.getElementById('file');
    const uploadWarning = document.getElementById('uploadWarning');
    const dateInput = document.getElementById('date');

    if (dateInput) {
      const validateDate = () => {
        const value = dateInput.value.trim();
        const isValid = /^\d{4}-\d{2}-\d{2}$/.test(value);
        dateInput.setCustomValidity(isValid ? '' : 'Formato de fecha inválido');
      };
      dateInput.addEventListener('change', validateDate);
      dateInput.addEventListener('input', validateDate);
    }

    if (uploadForm) {
      const submitButton = uploadForm.querySelector('button[type="submit"]');
      if (fileInput) {
        fileInput.addEventListener('change', () => {
          const selected = fileInput.files[0];
          if (selected && selected.size > 10 * 1024 * 1024) {
            uploadWarning.classList.remove('hidden');
          } else {
            uploadWarning.classList.add('hidden');
          }
        });
      }

      uploadForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!uploadWarning) {
          return;
        }
        const selectedFile = fileInput ? fileInput.files[0] : null;
        if (selectedFile && selectedFile.size > 10 * 1024 * 1024) {
          uploadWarning.classList.remove('hidden');
          return;
        }
        uploadWarning.classList.add('hidden');

        const codesArea = uploadForm.codes;
        if (codesArea) {
          const normalized = codesArea.value
            .split(/\r?\n/)
            .map((value) => value.trim())
            .filter(Boolean)
            .sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
          codesArea.value = normalized.join('\n');
        }

        const action = uploadForm.id.value ? 'edit' : 'upload';
        const formData = new FormData(uploadForm);
        formData.append('action', action);
        appendCsrf(formData);

        setButtonLoading(submitButton, true);
        try {
          const payload = await fetchJson(apiUrl, { method: 'POST', body: formData }, { showSpinner: true });
          toast(payload.message || 'Documento guardado');
          uploadForm.reset();
          uploadWarning.classList.add('hidden');
          updateCsrfToken(csrfToken);
          await refresh();
        } catch (error) {
          // error handled via toast
        } finally {
          setButtonLoading(submitButton, false);
        }
      });
    }

    window.clearConsultFilter = () => {
      const filter = document.getElementById('consultFilterInput');
      const listResults = document.getElementById('results-list');
      if (filter) {
        filter.value = '';
      }
      if (listResults) {
        listResults.innerHTML = '';
      }
    };

    window.doConsultFilter = () => {
      const filter = document.getElementById('consultFilterInput');
      if (!filter) {
        return;
      }
      const term = filter.value.trim().toLowerCase();
      render(fullList.filter((doc) => doc.name.toLowerCase().includes(term) || doc.path.toLowerCase().includes(term)), 'results-list', false);
    };

    window.downloadCsv = () => {
      let csv = 'Código,Documento\n';
      fullList.forEach((doc) => {
        (doc.codes || []).forEach((code) => {
          csv += `${code},${doc.name}\n`;
        });
      });
      const blob = new Blob([csv], { type: 'text/csv' });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = 'documentos.csv';
      anchor.click();
      URL.revokeObjectURL(url);
    };

    window.downloadPdfs = () => {
      window.location.href = `${apiUrl}&action=download_pdfs`;
    };

    const codeInput = document.getElementById('codeInput');
    const suggestions = document.getElementById('suggestions');
    let suggestionTimeout;

    if (codeInput && suggestions) {
      codeInput.addEventListener('input', () => {
        window.clearTimeout(suggestionTimeout);
        const term = codeInput.value.trim();
        if (!term) {
          suggestions.classList.add('hidden');
          suggestions.innerHTML = '';
          return;
        }
        suggestionTimeout = window.setTimeout(async () => {
          try {
            const results = await fetchJson(`${apiUrl}&action=suggest&term=${encodeURIComponent(term)}`);
            if (!Array.isArray(results) || results.length === 0) {
              suggestions.classList.add('hidden');
              suggestions.innerHTML = '';
              return;
            }
            suggestions.innerHTML = results
              .map((code) => `<div class="py-1 px-2 hover:bg-gray-100 cursor-pointer" data-code="${code}">${code}</div>`)
              .join('');
            suggestions.classList.remove('hidden');
          } catch (error) {
            console.error(error);
            suggestions.classList.add('hidden');
            suggestions.innerHTML = '';
          }
        }, 200);
      });

      suggestions.addEventListener('click', (event) => {
        const { code } = event.target.dataset;
        if (!code) {
          return;
        }
        codeInput.value = code;
        suggestions.classList.add('hidden');
        suggestions.innerHTML = '';
        doCodeSearch();
      });

      codeInput.addEventListener('blur', () => {
        window.setTimeout(() => {
          suggestions.classList.add('hidden');
        }, 100);
      });
    }

    window.clearCode = () => {
      const container = document.getElementById('results-code');
      if (container) {
        container.innerHTML = '';
      }
    };

    async function doCodeSearch() {
      if (!codeInput) {
        return;
      }
      const code = codeInput.value.trim();
      if (!code) {
        return;
      }
      const body = new FormData();
      body.append('action', 'search_by_code');
      body.append('code', code);
      appendCsrf(body);
      let data;
      try {
        data = await fetchJson(apiUrl, { method: 'POST', body });
      } catch (error) {
        return;
      }
      const results = document.getElementById('results-code');
      if (!results) {
        return;
      }
      results.innerHTML = '';
      const rows = Array.isArray(data) ? data : [];
      if (!rows.length) {
        results.innerHTML = '<p class="text-gray-500">No hay documentos.</p>';
      } else {
        render(rows, 'results-code', true);
      }
    }

    window.doCodeSearch = doCodeSearch;

    window.editDoc = (id) => {
      const doc = fullList.find((item) => item.id === id);
      if (!doc) {
        return;
      }
      const uploadTab = document.querySelector('[data-tab="tab-upload"]');
      if (uploadTab) {
        uploadTab.click();
      }
      const docId = document.getElementById('docId');
      const name = document.getElementById('name');
      const date = document.getElementById('date');
      const codes = document.getElementById('codes');
      if (docId) {
        docId.value = doc.id;
      }
      if (name) {
        name.value = doc.name;
      }
      if (date) {
        date.value = doc.date;
      }
      if (codes) {
        codes.value = (doc.codes || []).join('\n');
      }
    };

    async function deleteDoc(id, deletionKey) {
      try {
        const body = makeForm({ action: 'delete', id, deletion_key: deletionKey, confirm: 'yes' });
        const payload = await fetchJson(apiUrl, { method: 'POST', body }, { showSpinner: true });
        toast(payload.message || 'Documento eliminado');
        const active = document.querySelector('.tab.active');
        if (active) {
          active.click();
        }
        pendingDeleteId = null;
        return true;
      } catch (error) {
        console.error(error);
      }
      return false;
    }

    window.deleteDoc = deleteDoc;

    window.toggleCodes = (button) => {
      const id = button.dataset.id;
      const pre = document.getElementById(`codes${id}`);
      if (!pre) {
        return;
      }
      if (pre.classList.toggle('hidden')) {
        button.textContent = 'Ver Códigos';
        startPolling(refresh);
      } else {
        button.textContent = 'Ocultar Códigos';
        stopPolling();
      }
    };

    refresh();
    startPolling(refresh);
  }

  const loginOverlay = document.getElementById('loginOverlay');
  const mainContent = document.getElementById('mainContent');
  const accessInput = document.getElementById('accessInput');
  const submitAccess = document.getElementById('submitAccess');
  const errorMsg = document.getElementById('errorMsg');

  async function initializeSession() {
    try {
      const payload = await fetchJson(`${apiUrl}&action=session`, {}, { showSpinner: true });
      if (payload && payload.csrf_token) {
        updateCsrfToken(payload.csrf_token);
      }
      if (payload && payload.authenticated) {
        loginOverlay.classList.add('hidden');
        mainContent.classList.remove('hidden');
        initApp();
      } else {
        loginOverlay.classList.remove('hidden');
        mainContent.classList.add('hidden');
        if (accessInput) {
          accessInput.focus();
        }
      }
    } catch (error) {
      console.error(error);
      toast('No se pudo verificar la sesión.');
      loginOverlay.classList.remove('hidden');
      if (accessInput) {
        accessInput.focus();
      }
    }
  }

  if (submitAccess) {
    submitAccess.addEventListener('click', async () => {
      const value = accessInput.value.trim();
      if (!value) {
        errorMsg.classList.remove('hidden');
        return;
      }
      errorMsg.classList.add('hidden');
      const body = makeForm({ action: 'authenticate', access_key: value });
      setButtonLoading(submitAccess, true);
      try {
        const payload = await fetchJson(apiUrl, { method: 'POST', body }, { showSpinner: true });
        if (payload && payload.ok) {
          loginOverlay.classList.add('hidden');
          mainContent.classList.remove('hidden');
          initApp();
        } else {
          errorMsg.classList.remove('hidden');
        }
      } catch (error) {
        console.error(error);
        errorMsg.classList.remove('hidden');
      } finally {
        setButtonLoading(submitAccess, false);
      }
    });
  }

  if (accessInput) {
    accessInput.addEventListener('keypress', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        submitAccess.click();
      }
    });
  }

  initializeSession();
});
