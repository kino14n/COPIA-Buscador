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

  function startPolling(refreshFn) {
    stopPolling();
    intervalId = window.setInterval(refreshFn, 60000);
  }

  function stopPolling() {
    if (intervalId !== null) {
      window.clearInterval(intervalId);
    }
    intervalId = null;
  }

  function makeForm(data) {
    const formData = new URLSearchParams();
    Object.entries(data).forEach(([key, value]) => {
      if (value !== undefined && value !== null) {
        formData.append(key, value);
      }
    });
    return formData;
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

  function confirmDialog(message) {
    return new Promise((resolve) => {
      const overlay = document.getElementById('confirmOverlay');
      if (!overlay) {
        resolve(false);
        return;
      }
      document.getElementById('confirmMsg').textContent = message;
      overlay.classList.remove('hidden');
      document.getElementById('confirmOk').onclick = () => {
        overlay.classList.add('hidden');
        resolve(true);
      };
      document.getElementById('confirmCancel').onclick = () => {
        overlay.classList.add('hidden');
        resolve(false);
      };
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
    const deleteOverlay = document.getElementById('deleteOverlay');
    const deleteKeyInput = document.getElementById('deleteKeyInput');
    const deleteKeyError = document.getElementById('deleteKeyError');
    const tabs = document.querySelectorAll('.tab');
    const tabContents = document.querySelectorAll('.tab-content');

    window.openDeleteOverlay = (id) => {
      pendingDeleteId = id;
      deleteKeyError.classList.add('hidden');
      deleteKeyInput.value = '';
      deleteOverlay.classList.remove('hidden');
      deleteKeyInput.focus();
    };

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
        const response = await fetch(`${apiUrl}&action=list&page=1&per_page=0`, { credentials: 'same-origin' });
        const payload = await response.json();
        if (payload && payload.error) {
          toast(payload.error);
          return;
        }
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
        toast('Error al cargar documentos');
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
      const response = await fetch(apiUrl, { method: 'POST', body, credentials: 'same-origin' });
      const data = await response.json();
      if (data && data.error) {
        toast(data.error);
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

    if (uploadForm) {
      uploadForm.onsubmit = async (event) => {
        event.preventDefault();
        if (!fileInput || !uploadWarning) {
          return;
        }
        const selectedFile = fileInput.files[0];
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

        const response = await fetch(apiUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
        const payload = await response.json();
        if (payload && payload.error) {
          toast(payload.error);
          return;
        }
        toast(payload.message || 'Documento guardado');
        uploadForm.reset();
        uploadWarning.classList.add('hidden');
        await refresh();
      };
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
            const response = await fetch(`${apiUrl}&action=suggest&term=${encodeURIComponent(term)}`, { credentials: 'same-origin' });
            const results = await response.json();
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
      const response = await fetch(apiUrl, { method: 'POST', body, credentials: 'same-origin' });
      const data = await response.json();
      if (data && data.error) {
        toast(data.error);
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
        const response = await fetch(apiUrl, { method: 'POST', body, credentials: 'same-origin' });
        const payload = await response.json();
        if (response.ok && payload && !payload.error) {
          toast(payload.message || 'Documento eliminado');
          const active = document.querySelector('.tab.active');
          if (active) {
            active.click();
          }
          pendingDeleteId = null;
          return true;
        }
        toast(payload.error || 'No se pudo eliminar el documento');
      } catch (error) {
        console.error(error);
        toast('Error eliminando el documento');
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

  if (submitAccess) {
    submitAccess.onclick = async () => {
      const value = accessInput.value.trim();
      if (!value) {
        errorMsg.classList.remove('hidden');
        return;
      }
      errorMsg.classList.add('hidden');
      const body = makeForm({ action: 'authenticate', access_key: value });
      try {
        const response = await fetch(apiUrl, { method: 'POST', body, credentials: 'same-origin' });
        const payload = await response.json();
        if (response.ok && payload && payload.ok) {
          loginOverlay.classList.add('hidden');
          mainContent.classList.remove('hidden');
          initApp();
        } else {
          errorMsg.classList.remove('hidden');
        }
      } catch (error) {
        console.error(error);
        errorMsg.classList.remove('hidden');
      }
    };
  }

  if (accessInput) {
    accessInput.addEventListener('keypress', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        submitAccess.click();
      }
    });
  }
});
