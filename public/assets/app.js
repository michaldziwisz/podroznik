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

  function disableNativeDateTimePickersOnIOS() {
    if (!isIOS()) return;
    const inputs = document.querySelectorAll('input[type="date"], input[type="time"]');
    for (const input of inputs) {
      if (!(input instanceof HTMLInputElement)) continue;
      const t = (input.getAttribute('type') || '').toLowerCase();
      if (t !== 'date' && t !== 'time') continue;
      input.dataset.epOriginalType = t;
      input.setAttribute('type', 'text');
      input.autocomplete = 'off';
      input.inputMode = 'numeric';
      if (t === 'date' && !input.placeholder) {
        input.placeholder = 'YYYY-MM-DD';
      }
      if (t === 'time' && !input.placeholder) {
        input.placeholder = 'HH:MM';
      }
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

    function closeList() {
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
          const suggestions = Array.isArray(data && data.suggestions) ? data.suggestions : [];
          items = suggestions
            .filter((s) => s && typeof s === 'object')
            .map((s) => ({
              label: normalizeWhitespace(s.label),
              info: normalizeWhitespace(s.info),
              value: normalizeWhitespace(s.value),
            }))
            .filter((s) => s.label && s.value);

          activeIndex = items.length > 0 ? 0 : -1;
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

  function init() {
    disableNativeDateTimePickersOnIOS();
    const inputs = document.querySelectorAll('input[data-ep-suggest="1"]');
    for (const input of inputs) {
      if (input instanceof HTMLInputElement) {
        initAutocomplete(input);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
