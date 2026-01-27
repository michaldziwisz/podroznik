(() => {
  'use strict';

  function clamp(n, min, max) {
    return Math.max(min, Math.min(max, n));
  }

  function normalizeWhitespace(text) {
    return (text || '').toString().replace(/\s+/g, ' ').trim();
  }

  function buildSuggestUrl({ q, kind, type, limit }) {
    const url = new URL('/api/suggest', window.location.origin);
    url.searchParams.set('q', q);
    url.searchParams.set('kind', kind);
    url.searchParams.set('type', type);
    url.searchParams.set('limit', String(limit));
    return url.toString();
  }

  function isIOS() {
    const ua = navigator.userAgent || '';
    if (/iP(hone|od|ad)/.test(ua)) return true;
    // iPadOS (desktop UA)
    return navigator.platform === 'MacIntel' && (navigator.maxTouchPoints || 0) > 1;
  }

  function normalizeDateValue(value) {
    const v = normalizeWhitespace(value);
    if (!v) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(v)) return v;
    const m1 = v.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
    if (m1) {
      const d = String(m1[1]).padStart(2, '0');
      const mo = String(m1[2]).padStart(2, '0');
      const y = String(m1[3]).padStart(4, '0');
      return `${y}-${mo}-${d}`;
    }
    const m2 = v.match(/^(\d{4})(\d{2})(\d{2})$/);
    if (m2) {
      return `${m2[1]}-${m2[2]}-${m2[3]}`;
    }
    return v;
  }

  function normalizeTimeValue(value) {
    const v = normalizeWhitespace(value).replace(/[.,]/g, ':');
    if (!v) return '';
    const m1 = v.match(/^(\d{1,2}):(\d{1,2})$/);
    if (m1) {
      const h = String(parseInt(m1[1], 10)).padStart(2, '0');
      const min = String(parseInt(m1[2], 10)).padStart(2, '0');
      return `${h}:${min}`;
    }
    const m2 = v.match(/^(\d{1,4})$/);
    if (m2) {
      const digits = m2[1];
      if (digits.length <= 2) {
        const h = String(parseInt(digits, 10)).padStart(2, '0');
        return `${h}:00`;
      }
      const h = String(parseInt(digits.slice(0, -2), 10)).padStart(2, '0');
      const min = String(parseInt(digits.slice(-2), 10)).padStart(2, '0');
      return `${h}:${min}`;
    }
    return v;
  }

  function iosDateTimePickersOnActivate() {
    if (!isIOS()) return;

    const inputs = document.querySelectorAll('input[type="date"], input[type="time"]');
    for (const input of inputs) {
      if (!(input instanceof HTMLInputElement)) continue;
      if (input.dataset.epIosDateTime === '1') continue;

      const nativeType = (input.getAttribute('type') || '').toLowerCase();
      if (nativeType !== 'date' && nativeType !== 'time') continue;

      input.dataset.epIosDateTime = '1';
      input.dataset.epIosNativeType = nativeType;

      input.setAttribute('type', 'text');
      input.autocomplete = 'off';
      input.spellcheck = false;
      input.inputMode = 'numeric';

      let inNative = false;

      function toNative() {
        if (inNative) return;
        inNative = true;

        const raw = (input.value || '').trim();
        const normalized = nativeType === 'date' ? normalizeDateValue(raw) : normalizeTimeValue(raw);

        input.setAttribute('type', nativeType);
        input.value = normalized;
      }

      function toText() {
        if (!inNative) return;
        inNative = false;
        const v = input.value;
        input.setAttribute('type', 'text');
        input.value = v;
      }

      // Switch before the user "click" lands, so native UI opens as a real interaction.
      input.addEventListener('pointerdown', () => {
        toNative();
      });
      input.addEventListener('touchstart', () => {
        toNative();
      }, { passive: true });
      input.addEventListener('mousedown', () => {
        toNative();
      });

      input.addEventListener('click', () => {
        toNative();
        try {
          if (typeof input.showPicker === 'function') {
            input.showPicker();
          }
        } catch (_) {
          // ignore
        }
      });

      input.addEventListener('change', () => {
        window.setTimeout(() => toText(), 0);
      });

      input.addEventListener('blur', () => {
        window.setTimeout(() => toText(), 300);
      });
    }
  }

  function initAutocomplete(input) {
    const kind = (input.dataset.epKind || 'SOURCE').toUpperCase();
    const type = (input.dataset.epType || 'ALL').toUpperCase();
    const hiddenId = input.dataset.epHidden || '';
    const listId = input.dataset.epList || '';
    const statusId = input.dataset.epStatus || '';

    const hidden = hiddenId ? document.getElementById(hiddenId) : null;
    const list = listId ? document.getElementById(listId) : null;
    const status = statusId ? document.getElementById(statusId) : null;

    if (!list || !(list instanceof HTMLElement)) return;

    input.setAttribute('aria-haspopup', 'listbox');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-expanded', 'false');

    let open = false;
    let activeIndex = -1;
    let items = [];
    let debounceTimer = null;
    let abortController = null;
    let lastQuery = '';

    const minChars = 2;
    const limit = clamp(parseInt(input.dataset.epLimit || '8', 10) || 8, 1, 20);
    const debounceMs = clamp(parseInt(input.dataset.epDebounceMs || '200', 10) || 200, 0, 2000);

    function setStatus(text) {
      if (!status) return;
      status.textContent = normalizeWhitespace(text);
    }

    function cancelPending() {
      if (debounceTimer) {
        window.clearTimeout(debounceTimer);
        debounceTimer = null;
      }
      if (abortController) {
        try {
          abortController.abort();
        } catch (_) {
          // ignore
        }
        abortController = null;
      }
    }

    function closeList() {
      cancelPending();
      open = false;
      activeIndex = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      list.hidden = true;
      list.innerHTML = '';
      items = [];
      setStatus('');
    }

    function ensureOpen() {
      if (open) return;
      open = true;
      input.setAttribute('aria-expanded', 'true');
      list.hidden = false;
    }

    function renderList() {
      list.innerHTML = '';

      if (items.length === 0) {
        closeList();
        return;
      }

      ensureOpen();

      for (let i = 0; i < items.length; i++) {
        const s = items[i];
        const li = document.createElement('li');
        li.id = `${list.id}_opt_${i}`;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', i === activeIndex ? 'true' : 'false');
        li.dataset.value = s.value || '';
        li.dataset.label = s.label || '';
        li.dataset.info = s.info || '';

        const label = document.createElement('span');
        label.className = 'ac-label';
        label.textContent = s.label || '';
        li.appendChild(label);

        if (s.info) {
          const info = document.createElement('span');
          info.className = 'ac-info';
          info.textContent = s.info;
          li.appendChild(info);
        }

        let handled = false;
        li.addEventListener('pointerdown', (ev) => {
          handled = true;
          ev.preventDefault();
          selectIndex(i);
        });
        li.addEventListener('click', (ev) => {
          if (handled) return;
          ev.preventDefault();
          selectIndex(i);
        });

        list.appendChild(li);
      }

      if (activeIndex >= 0) {
        input.setAttribute('aria-activedescendant', `${list.id}_opt_${activeIndex}`);
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function setActive(nextIndex) {
      if (!open || items.length === 0) return;
      activeIndex = clamp(nextIndex, 0, items.length - 1);
      renderList();
      const activeEl = document.getElementById(`${list.id}_opt_${activeIndex}`);
      if (activeEl) {
        activeEl.scrollIntoView({ block: 'nearest' });
      }
    }

    function selectIndex(index) {
      if (index < 0 || index >= items.length) return;
      const s = items[index];
      // Prevent any scheduled fetch from re-opening the list and hijacking the selection.
      cancelPending();
      input.value = s.label || '';
      if (hidden && hidden instanceof HTMLInputElement) {
        hidden.value = s.value || '';
      }
      setStatus(`Wybrano: ${s.label}${s.info ? ' — ' + s.info : ''}.`);
      closeList();
    }

    async function fetchSuggestions(query) {
      if (abortController) abortController.abort();
      abortController = new AbortController();

      const url = buildSuggestUrl({ q: query, kind, type, limit });
      const resp = await fetch(url, {
        method: 'GET',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
        signal: abortController.signal,
      });
      if (!resp.ok) {
        throw new Error(`HTTP ${resp.status}`);
      }
      return await resp.json();
    }

    function scheduleFetch() {
      if (debounceTimer) window.clearTimeout(debounceTimer);
      debounceTimer = window.setTimeout(async () => {
        const q = normalizeWhitespace(input.value);
        if (q.length < minChars) {
          closeList();
          return;
        }
        if (q === lastQuery && open) {
          return;
        }
        lastQuery = q;
        try {
          const data = await fetchSuggestions(q);
          const prevActiveValue =
            open && activeIndex >= 0 && activeIndex < items.length ? items[activeIndex].value : '';
          const prevActiveLabel =
            open && activeIndex >= 0 && activeIndex < items.length ? items[activeIndex].label : '';
          const suggestions = Array.isArray(data && data.suggestions) ? data.suggestions : [];
          const nextItems = suggestions
            .filter((s) => s && typeof s === 'object')
            .map((s) => ({
              label: normalizeWhitespace(s.label),
              info: normalizeWhitespace(s.info),
              value: normalizeWhitespace(s.value),
            }))
            .filter((s) => s.label && s.value);

          items = nextItems;

          if (items.length === 0) {
            closeList();
            return;
          }

          // If the user already navigated the list, keep their selection when the list refreshes.
          // This avoids "jumping cursor" issues (e.g. selecting an option, then it snaps back to index 0).
          let nextIndex = -1;
          if (prevActiveValue) {
            nextIndex = items.findIndex((s) => s.value === prevActiveValue);
          }
          if (nextIndex < 0 && prevActiveLabel) {
            nextIndex = items.findIndex((s) => s.label === prevActiveLabel);
          }
          activeIndex = nextIndex >= 0 ? nextIndex : 0;

          renderList();
          if (items.length > 0) {
            setStatus(`Podpowiedzi: ${items.length}. Użyj strzałek góra/dół i Enter albo stuknij podpowiedź.`);
          }
        } catch (e) {
          if (e && e.name === 'AbortError') return;
          closeList();
        }
      }, debounceMs);
    }

    input.addEventListener('input', () => {
      if (hidden && hidden instanceof HTMLInputElement) {
        hidden.value = '';
      }
      scheduleFetch();
    });

    input.addEventListener('focus', () => {
      if (normalizeWhitespace(input.value).length >= minChars) {
        scheduleFetch();
      }
    });

    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowDown') {
        if (!open && normalizeWhitespace(input.value).length >= minChars) {
          scheduleFetch();
        }
        if (items.length > 0) {
          ev.preventDefault();
          setActive(activeIndex < 0 ? 0 : activeIndex + 1);
        }
        return;
      }
      if (ev.key === 'ArrowUp') {
        if (items.length > 0) {
          ev.preventDefault();
          setActive(activeIndex < 0 ? 0 : activeIndex - 1);
        }
        return;
      }
      if (ev.key === 'Enter') {
        if (open && activeIndex >= 0 && items.length > 0) {
          ev.preventDefault();
          selectIndex(activeIndex);
        }
        return;
      }
      if (ev.key === 'Escape') {
        if (open) {
          ev.preventDefault();
          closeList();
        }
        return;
      }
      if (ev.key === 'Tab') {
        if (open && activeIndex >= 0 && items.length > 0) {
          selectIndex(activeIndex);
        }
      }
    });

    input.addEventListener('blur', () => {
      window.setTimeout(() => closeList(), 300);
    });

    document.addEventListener('click', (ev) => {
      const t = ev.target;
      if (!(t instanceof Node)) return;
      if (t === input) return;
      if (list.contains(t)) return;
      closeList();
    });

    if (normalizeWhitespace(input.value).length >= minChars) {
      // If page is loaded with pre-filled values, keep hidden value empty (server fallback),
      // but allow the user to open suggestions by focusing the field.
      closeList();
    }
  }

  function initTicketHandoff() {
    const forms = document.querySelectorAll('form[data-ep-ticket-handoff="1"]');
    for (const form of forms) {
      if (!(form instanceof HTMLFormElement)) continue;
      if (form.dataset.epTicketHandoffInit === '1') continue;
      form.dataset.epTicketHandoffInit = '1';

      form.addEventListener('submit', (ev) => {
        const defineUrl = normalizeWhitespace(form.dataset.epDefineUrl || '');
        const winName = normalizeWhitespace(form.dataset.epWindow || 'epbuy');
        if (!defineUrl) return;

        // Submit the POST into a named new tab/window (user-initiated submit).
        // We avoid calling window.open() preemptively (can be blocked on some setups),
        // and instead grab a reference to the named window after submit.
        form.target = winName;

        window.setTimeout(() => {
          let win = null;
          try {
            win = window.open('', winName);
          } catch (_) {
            win = null;
          }
          if (!win) return;

          try {
            win.opener = null;
          } catch (_) {
            // ignore
          }

          try {
            win.location.href = defineUrl;
          } catch (_) {
            // ignore
          }
        }, 3500);
      });
    }
  }

  function init() {
    iosDateTimePickersOnActivate();
    const inputs = document.querySelectorAll('input[data-ep-suggest="1"]');
    for (const input of inputs) {
      if (input instanceof HTMLInputElement) {
        initAutocomplete(input);
      }
    }
    initTicketHandoff();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
