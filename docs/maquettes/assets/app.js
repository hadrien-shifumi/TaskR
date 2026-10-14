/* Taskr — interactions de maquette (dialog, menu, tabs, filtres, drag, rôles) */
(function () {
  'use strict';

  /* ── dialogs ── */
  function closeDialog(overlay) {
    overlay.removeAttribute('open');
    document.body.style.overflow = '';
    var returnTo = overlay._returnTo;
    if (returnTo && returnTo.isConnected) returnTo.focus();
  }
  function openDialog(id, returnTo) {
    var overlay = document.getElementById(id);
    if (!overlay) return;
    overlay._returnTo = returnTo || null;
    overlay.setAttribute('open', '');
    document.body.style.overflow = 'hidden';
    var af = overlay.querySelector('[data-autofocus]');
    if (af) af.focus();
  }
  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-dialog-open]');
    if (opener) {
      var id = opener.getAttribute('data-dialog-open');
      var overlay = document.getElementById(id);
      if (overlay && !overlay.hasAttribute('open')) openDialog(id, opener);
      return;
    }
    if (e.target.closest('[data-dialog-close]')) {
      var ov = e.target.closest('.dialog-overlay');
      if (ov) closeDialog(ov);
      return;
    }
    var ovl = e.target.closest('.dialog-overlay');
    if (ovl && e.target === ovl) closeDialog(ovl);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var ov = document.querySelector('.dialog-overlay[open]');
    if (ov) { closeDialog(ov); return; }
    var openMenu = document.querySelector('.menu-wrap[data-open]');
    if (openMenu) openMenu.removeAttribute('data-open');
  });

  /* ── menus déroulants ── */
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-menu-trigger]');
    var wrap = trigger && trigger.closest('.menu-wrap');
    if (trigger) {
      if (wrap.hasAttribute('data-open')) wrap.removeAttribute('data-open');
      else {
        var other = document.querySelector('.menu-wrap[data-open]');
        if (other) other.removeAttribute('data-open');
        wrap.setAttribute('data-open', '');
        var first = wrap.querySelector('.menu button, .menu a');
        if (first) first.focus();
      }
      return;
    }
    if (!e.target.closest('.menu')) {
      var open = document.querySelector('.menu-wrap[data-open]');
      if (open) open.removeAttribute('data-open');
    }
  });

  /* ── tabs ── */
  document.addEventListener('click', function (e) {
    var tab = e.target.closest('.tabs button[data-tab]');
    if (!tab) return;
    var tabs = tab.closest('.tabs');
    var panel = tab.getAttribute('data-tab');
    tabs.querySelectorAll('button').forEach(function (b) {
      b.setAttribute('aria-selected', b === tab ? 'true' : 'false');
    });
    document.querySelectorAll('.tab-panel').forEach(function (p) {
      p.hidden = p.getAttribute('data-panel') !== panel;
    });
  });

  /* ── switchs (rôle simulé, states) ── */
  document.addEventListener('click', function (e) {
    var sw = e.target.closest('.switch');
    if (!sw) return;
    var on = sw.getAttribute('aria-checked') === 'true';
    sw.setAttribute('aria-checked', on ? 'false' : 'true');
    sw.dispatchEvent(new CustomEvent('toggle', { bubbles: true, detail: { checked: !on } }));
  });

  /* ── filtres de liste (data-filter sur les lignes) ── */
  document.querySelectorAll('[data-filter-bar]').forEach(function (bar) {
    var scope = document.querySelector('[data-filter-scope]') || document;
    function apply() {
      var states = {};
      bar.querySelectorAll('[data-filter-key]').forEach(function (chip) {
        var key = chip.getAttribute('data-filter-key');
        states[key] = chip.classList.contains('active');
      });
      var q = (bar.querySelector('[data-filter-search]') || {}).value || '';
      q = q.trim().toLowerCase();
      var shown = 0, total = 0;
      scope.querySelectorAll('[data-filter-row]').forEach(function (row) {
        total++;
        var show = true;
        Object.keys(states).forEach(function (key) {
          if (!states[key]) return;
          // chip active => la ligne doit porter data-filter-<key>=true
          var rowVal = row.getAttribute('data-filter-' + key) || 'false';
          if (rowVal !== 'true') show = false;
        });
        if (q) {
          var hay = (row.textContent || '').toLowerCase();
          if (hay.indexOf(q) === -1) show = false;
        }
        row.style.display = show ? '' : 'none';
        if (show) shown++;
      });
      var empty = document.querySelector('[data-filter-empty]');
      if (empty) empty.hidden = shown > 0;
      var counter = document.querySelector('[data-filter-count]');
      if (counter) counter.textContent = shown + ' / ' + total;
    }
    bar.addEventListener('click', function (e) {
      var chip = e.target.closest('[data-filter-key]');
      if (!chip) return;
      chip.classList.toggle('active');
      apply();
    });
    var search = bar.querySelector('[data-filter-search]');
    if (search) search.addEventListener('input', apply);
    apply();
  });

  /* ── validation inline (titre requis) ── */
  document.querySelectorAll('form[data-validate]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var ok = true;
      form.querySelectorAll('[data-required]').forEach(function (input) {
        var field = input.closest('.field');
        if (!input.value.trim()) {
          ok = false;
          input.setAttribute('aria-invalid', 'true');
          if (field) field.classList.add('has-error');
        } else {
          input.removeAttribute('aria-invalid');
          if (field) field.classList.remove('has-error');
        }
      });
      if (!ok) return;
      var overlay = form.closest('.dialog-overlay');
      if (overlay) closeDialog(overlay);
      else form.reset();
    });
  });

  /* ── drag & drop (réordonnancement des statuts) ── */
  var list = document.querySelector('.drag-list[data-drag]');
  if (list) {
    var dragged = null;
    list.querySelectorAll('.drag-row').forEach(function (row) {
      row.setAttribute('draggable', 'true');
      row.addEventListener('dragstart', function () {
        dragged = row; row.classList.add('dragging');
      });
      row.addEventListener('dragend', function () {
        row.classList.remove('dragging');
        list.querySelectorAll('.drag-row').forEach(function (r) { r.classList.remove('drag-over'); });
        dragged = null;
      });
      row.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!dragged || row === dragged) return;
        row.classList.add('drag-over');
        var rect = row.getBoundingClientRect();
        var after = e.clientY > rect.top + rect.height / 2;
        list.insertBefore(dragged, after ? row.nextSibling : row);
      });
      row.addEventListener('dragleave', function () { row.classList.remove('drag-over'); });
    });
  }

  /* ── kanban : glisser-déposer une carte entre les statuts ── */
  var board = document.querySelector('[data-kanban]');
  if (board) {
    var draggedCard = null;
    board.querySelectorAll('.kcard').forEach(function (card) {
      card.setAttribute('draggable', 'true');
      card.addEventListener('dragstart', function () {
        draggedCard = card;
        card.classList.add('dragging');
      });
      card.addEventListener('dragend', function () {
        card.classList.remove('dragging');
        board.querySelectorAll('.board-cards').forEach(function (z) { z.classList.remove('drop-hint'); });
        draggedCard = null;
        updateBoardCounts();
      });
    });
    function updateBoardCounts() {
      board.querySelectorAll('.board-col').forEach(function (col) {
        var zone = col.querySelector('.board-cards');
        if (!zone) return;
        var n = col.querySelectorAll('.kcard').length;
        var counter = col.querySelector('[data-col-count]');
        if (counter) counter.textContent = n;
        var empty = zone.querySelector('.board-empty');
        if (n === 0 && !empty) {
          var ph = document.createElement('div');
          ph.className = 'board-empty';
          ph.textContent = 'Aucune issue — glissez une carte ici';
          zone.appendChild(ph);
        } else if (n > 0 && empty) {
          empty.remove();
        }
      });
    }
    board.querySelectorAll('.board-cards').forEach(function (zone) {
      zone.addEventListener('dragover', function (e) {
        e.preventDefault();
        zone.classList.add('drop-hint');
      });
      zone.addEventListener('dragleave', function () { zone.classList.remove('drop-hint'); });
      zone.addEventListener('drop', function (e) {
        e.preventDefault();
        zone.classList.remove('drop-hint');
        if (!draggedCard) return;
        var col = zone.closest('.board-col');
        var empty = zone.querySelector('.board-empty');
        if (empty) empty.remove();
        zone.appendChild(draggedCard);
        /* changement de statut libre : la carte adopte le statut de la colonne */
        var badge = draggedCard.querySelector('[data-status-badge]');
        if (badge && col) {
          badge.className = 'badge badge-cat-' + col.getAttribute('data-status-cat');
          badge.innerHTML = '<span class="dot"></span>' + col.getAttribute('data-status-name');
        }
      });
    });
  }

  /* ── bascule de rôle simulée ── */
  var roleToggle = document.getElementById('role-switch');
  if (roleToggle) {
    roleToggle.addEventListener('toggle', function (e) {
      var isMember = e.detail.checked;
      document.querySelectorAll('[data-owner-only]').forEach(function (el) {
        if (isMember) { el.setAttribute('aria-disabled', 'true'); el.setAttribute('tabindex', '-1'); }
        else { el.removeAttribute('aria-disabled'); el.removeAttribute('tabindex'); }
      });
      var banner = document.getElementById('readonly-banner');
      if (banner) banner.hidden = !isMember;
      var actions = document.querySelectorAll('[data-owner-action]');
      actions.forEach(function (el) { el.style.display = isMember ? 'none' : ''; });
      var label = document.getElementById('role-label');
      if (label) label.textContent = isMember ? 'member (lecture seule)' : 'owner';
    });
  }

  /* ── actions auteur-seul (commentaires / time entries) ── */
  document.querySelectorAll('[data-entry-delete]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var entry = btn.closest('.entry');
      if (entry) entry.remove();
    });
  });
})();
