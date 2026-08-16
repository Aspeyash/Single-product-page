/**
 * ZYMARG Vendor Dashboard -- Staff section JavaScript.
 *
 * Handles: add staff form, update permissions, remove staff.
 * Uses event delegation so it works after SPA section swaps.
 *
 * @package ZYMARG_Vendor_Dashboard
 */
(function () {
  'use strict';

  var cfg = window.ZymargStaff || {};
  if (!cfg.ajaxUrl) return;

  document.addEventListener('click', function (e) {
    // Save permissions button
    var saveBtn = e.target.closest('[data-staff-save]');
    if (saveBtn) {
      e.preventDefault();
      handleSavePerms(saveBtn);
      return;
    }

    // Remove staff button
    var removeBtn = e.target.closest('[data-staff-remove]');
    if (removeBtn) {
      e.preventDefault();
      handleRemove(removeBtn);
      return;
    }
  });

  document.addEventListener('submit', function (e) {
    var form = e.target.closest('#zymarg-staff-add-form');
    if (form) {
      e.preventDefault();
      handleAddStaff(form);
    }
  });

  // Re-bind after SPA section swap.
  document.addEventListener('zymarg-vd:section-loaded', function () {
    // Nothing extra needed -- event delegation handles it.
  });

  function handleAddStaff(form) {
    var msgEl = form.querySelector('#zymarg-staff-add-msg') || form.querySelector('.zymarg-staff-msg');
    var btn = form.querySelector('button[type="submit"]');
    var origText = btn ? btn.innerHTML : '';

    if (btn) btn.innerHTML = cfg.i18n.working;

    var fd = new FormData(form);
    fd.append('action', 'zymarg_vd_add_staff');
    fd.append('nonce', cfg.nonce);

    fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (btn) btn.innerHTML = origText;
        if (res.success) {
          showMsg(msgEl, res.data.message, 'success');
          form.reset();
          if (res.data.reload) {
            setTimeout(function () { location.reload(); }, 800);
          }
        } else {
          showMsg(msgEl, (res.data && res.data.message) || cfg.i18n.error, 'error');
        }
      })
      .catch(function () {
        if (btn) btn.innerHTML = origText;
        showMsg(msgEl, cfg.i18n.error, 'error');
      });
  }

  function handleSavePerms(btn) {
    var staffId = btn.getAttribute('data-staff-save');
    var card = btn.closest('.zymarg-staff-member');
    if (!card) return;

    var checkboxes = card.querySelectorAll('input[data-staff-perm="' + staffId + '"]');
    var perms = [];
    checkboxes.forEach(function (cb) {
      if (cb.checked) perms.push(cb.value);
    });

    var origText = btn.innerHTML;
    btn.innerHTML = cfg.i18n.working;

    var fd = new FormData();
    fd.append('action', 'zymarg_vd_update_staff_permissions');
    fd.append('nonce', cfg.nonce);
    fd.append('staff_id', staffId);
    perms.forEach(function (p) { fd.append('permissions[]', p); });

    fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.innerHTML = origText;
        if (res.success) {
          btn.style.color = '#0a0';
          setTimeout(function () { btn.style.color = ''; }, 1500);
        } else {
          alert((res.data && res.data.message) || cfg.i18n.error);
        }
      })
      .catch(function () {
        btn.innerHTML = origText;
        alert(cfg.i18n.error);
      });
  }

  function handleRemove(btn) {
    var staffId = btn.getAttribute('data-staff-remove');
    if (!confirm(cfg.i18n.confirmRemove)) return;

    var fd = new FormData();
    fd.append('action', 'zymarg_vd_remove_staff');
    fd.append('nonce', cfg.nonce);
    fd.append('staff_id', staffId);

    fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          var card = btn.closest('.zymarg-staff-member');
          if (card) card.style.display = 'none';
          if (res.data.reload) {
            setTimeout(function () { location.reload(); }, 600);
          }
        } else {
          alert((res.data && res.data.message) || cfg.i18n.error);
        }
      })
      .catch(function () {
        alert(cfg.i18n.error);
      });
  }

  function showMsg(el, text, type) {
    if (!el) return;
    el.textContent = text;
    el.className = 'zymarg-staff-msg zymarg-staff-msg--' + type;
    el.hidden = false;
    setTimeout(function () { el.hidden = true; }, 5000);
  }
})();
