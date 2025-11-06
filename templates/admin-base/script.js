(function (global) {
  'use strict';

  function ensureFunction(fn) {
    if (typeof fn !== 'function') {
      throw new TypeError('secureDeletion.create requiere un callback onSubmit válido');
    }
    return fn;
  }

  function ensureElement(selector, root) {
    const element = (root || document).querySelector(selector);
    if (!element) {
      throw new Error(`Elemento no encontrado para selector: ${selector}`);
    }
    return element;
  }

  function resetState(state) {
    state.input.value = '';
    state.error && state.error.classList.add('hidden');
    state.pendingId = null;
  }

  function createSecureDeletion(options) {
    const config = Object.assign({
      overlaySelector: '#deleteOverlay',
      inputSelector: '#deleteKeyInput',
      errorSelector: '#deleteKeyError',
      confirmSelector: '#deleteKeyOk',
      cancelSelector: '#deleteKeyCancel',
    }, options || {});

    const overlay = ensureElement(config.overlaySelector, config.root);
    const input = ensureElement(config.inputSelector, config.root);
    const confirmButton = ensureElement(config.confirmSelector, config.root);
    const cancelButton = ensureElement(config.cancelSelector, config.root);
    const errorBox = config.errorSelector ? ensureElement(config.errorSelector, config.root) : null;
    const onSubmit = ensureFunction(config.onSubmit);

    const state = {
      overlay,
      input,
      error: errorBox,
      pendingId: null,
    };

    function hideOverlay() {
      overlay.classList.add('hidden');
      resetState(state);
    }

    async function handleSubmit(event) {
      event.preventDefault();
      const key = input.value.trim();
      if (!key) {
        errorBox && errorBox.classList.remove('hidden');
        input.focus();
        return;
      }

      try {
        const ok = await onSubmit({ id: state.pendingId, key });
        if (ok) {
          hideOverlay();
          return;
        }
        errorBox && errorBox.classList.remove('hidden');
        input.select();
      } catch (error) {
        console.error('secureDeletion.create error:', error);
        errorBox && errorBox.classList.remove('hidden');
        input.select();
      }
    }

    function handleCancel(event) {
      event.preventDefault();
      hideOverlay();
    }

    confirmButton.addEventListener('click', handleSubmit);
    cancelButton.addEventListener('click', handleCancel);

    return {
      open(id) {
        state.pendingId = id;
        resetState(state);
        overlay.classList.remove('hidden');
        input.focus();
      },
      close: hideOverlay,
    };
  }

  if (!global.secureDeletion) {
    global.secureDeletion = {};
  }
  global.secureDeletion.create = createSecureDeletion;
})(window);
