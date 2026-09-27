(function () {
  'use strict';

  function text(value) {
    return (value || '').replace(/\s+/g, ' ').trim();
  }

  function enhance() {
    var form = document.getElementById('ModuleEditForm');
    if (!form) return;

    var saveMenu = form.querySelector('#pw-dropdown-toggle-Inputfield_submit_save_module');
    if (saveMenu && !text(saveMenu.getAttribute('aria-label'))) saveMenu.setAttribute('aria-label', 'Save options');

    form.querySelectorAll('select[id^="asmSelect"]').forEach(function (select) {
      if (text(select.getAttribute('aria-label'))) return;
      var field = select.closest('.Inputfield');
      var heading = field && field.querySelector('.InputfieldHeader, label');
      select.setAttribute('aria-label', (text(heading && heading.textContent) || 'Options') + ' add option');
    });

    form.querySelectorAll('.asmListItemRemove').forEach(function (link) {
      if (text(link.getAttribute('aria-label'))) return;
      var item = link.closest('.asmListItem');
      var label = item && item.querySelector('.asmListItemLabel');
      link.setAttribute('aria-label', 'Remove ' + (text(label && label.textContent) || 'selected option'));
    });

    form.querySelectorAll('.InputfieldContent').forEach(function (region) {
      if (region.scrollWidth <= region.clientWidth + 2) return;
      if (!region.hasAttribute('tabindex')) region.setAttribute('tabindex', '0');
      if (!text(region.getAttribute('aria-label'))) {
        var field = region.closest('.Inputfield');
        var heading = field && field.querySelector('.InputfieldHeader');
        region.setAttribute('aria-label', text(heading && heading.textContent) || 'Scrollable module settings');
      }
    });

    form.setAttribute('data-mrc-config-a11y-ready', '1');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhance, { once: true });
  else enhance();
  window.addEventListener('load', enhance, { once: true });
  window.addEventListener('resize', enhance);
}());
