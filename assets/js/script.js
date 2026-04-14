(() => {
  const sidebar = document.querySelector('.sidebar');
  const toggle = document.getElementById('sidebarToggle');
  if (sidebar && toggle) {
    toggle.addEventListener('click', () => {
      sidebar.classList.toggle('is-open');
    });
  }

  // Lightweight client timer rendering (server remains source of truth).
  // Elements:
  // - data-timer-start="2026-01-01 12:34:56" (server time)
  // - data-timer-mode="up"
  function parseServerDateTime(value) {
    // Expect "YYYY-MM-DD HH:MM:SS"
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/.exec(value || '');
    if (!m) return null;
    const [_, y, mo, d, h, mi, s] = m;
    return new Date(Number(y), Number(mo) - 1, Number(d), Number(h), Number(mi), Number(s));
  }

  function pad2(n) { return String(n).padStart(2, '0'); }
  function fmtDuration(ms) {
    const sec = Math.max(0, Math.floor(ms / 1000));
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return `${pad2(h)}:${pad2(m)}:${pad2(s)}`;
  }

  function tickTimers() {
    const nodes = document.querySelectorAll('[data-timer-start]');
    const now = new Date();
    nodes.forEach((el) => {
      const start = parseServerDateTime(el.getAttribute('data-timer-start'));
      if (!start) return;
      const diff = now.getTime() - start.getTime();
      el.textContent = fmtDuration(diff);
    });
  }

  tickTimers();
  setInterval(tickTimers, 1000);

  // ── Table Search & Filter ──
  // Bind generic filters
  function bindFilterLogic(searchInputId, containerId, filterClass) {
    const searchInput = document.getElementById(searchInputId);
    if (!searchInput) return;

    let currentFilter = 'all';
    const filterBtns = document.querySelectorAll(filterClass);

    function applyFilter() {
      const query = searchInput.value.toLowerCase().trim();
      // Search generically for any .table-card or tr with data-table-name
      const items = document.querySelectorAll(`[data-table-name]`);
      let visible = 0;

      items.forEach(row => {
        // Only filter the ones inside our target container if it's specific, but since we might share containers or have multiple grids, let's filter all that match the closest container parent
        if (containerId && !row.closest('#' + containerId)) return;

        const name = row.getAttribute('data-table-name') || '';
        const status = row.getAttribute('data-status') || '';

        const matchesSearch = !query || name.includes(query);
        const matchesFilter = currentFilter === 'all' || status === currentFilter;

        if (matchesSearch && matchesFilter) {
          row.style.display = row.tagName === 'TR' ? '' : 'block';
          visible++;
        } else {
          row.style.display = 'none';
        }
      });
      // Optionally handle no results here if we had a specific #noResults block per section
    }

    searchInput.addEventListener('input', applyFilter);

    if (filterBtns.length > 0) {
      filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
          currentFilter = btn.getAttribute('data-filter') || 'all';

          // Toggle active styling scoped to this block
          filterBtns.forEach(b => {
             b.classList.add('btn--ghost');
             b.classList.remove('is-active', 'btn--primary');
          });
          btn.classList.remove('btn--ghost');
          btn.classList.add('is-active', 'btn--primary');

          applyFilter();
        });
      });
    }
  }

  // Bind for various pages
  bindFilterLogic('tableSearch', 'tablesGrid', '.filter-btn');
  bindFilterLogic('tableSearch', 'tablesTable', '.filter-btn'); // For old layouts if any remain
  bindFilterLogic('vipSearch', 'vipGrid', '.vip-filter-btn');
  bindFilterLogic('ktvSearch', 'ktvGrid', '.ktv-filter-btn');

})();

