(function () {
  'use strict';

  const slug = value => String(value || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  const esc = value => String(value == null ? '' : value).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const number = (value, fallback = null) => value === '' || value == null ? fallback : Number(value);
  const keyFor = options => Object.keys(options || {}).sort().map(key => key + '=' + options[key]).join('|');
  const uid = prefix => prefix + '-' + Math.random().toString(36).slice(2, 9);

  function boot(root) {
    if (root.dataset.ready === '1') return;
    root.dataset.ready = '1';
    const hidden = root.querySelector('.mrc-variant-value');
    const optionList = root.querySelector('.mrc-option-list');
    const table = root.querySelector('.mrc-variant-table');
    const summary = root.querySelector('.mrc-variant-summary');
    const errors = root.querySelector('.mrc-variant-errors');
    let data;
    try { data = JSON.parse(hidden.value || '{}'); } catch (_) { data = {}; }
    data = {options: Array.isArray(data.options) ? data.options : [], variants: Array.isArray(data.variants) ? data.variants : []};
    let openVariant = '';

    const normalize = () => {
      data.options = data.options.map((option, position) => ({
        id: slug(option.id || option.label),
        label: String(option.label || option.id || ''),
        position,
        values: Array.isArray(option.values) ? option.values.map(value => typeof value === 'object'
          ? {id: slug(value.id || value.label), label: String(value.label || value.id || '')}
          : {id: slug(value), label: String(value)}) : []
      }));
      data.variants = data.variants.map(variant => ({
        id: slug(variant.id || Object.values(variant.options || {}).join('-')),
        options: Object.assign({}, variant.options || {}),
        sku: String(variant.sku || ''),
        price: variant.price === '' || variant.price == null ? null : Number(variant.price),
        price_adjustment: Number(variant.price_adjustment || 0),
        stock: Number.parseInt(variant.stock || 0, 10),
        low_stock_threshold: Math.max(0, Number.parseInt(variant.low_stock_threshold || 0, 10)),
        stock_policy: ['deny','backorder','preorder'].includes(variant.stock_policy) ? variant.stock_policy : 'deny',
        status: ['active','unavailable','archived'].includes(variant.status) ? variant.status : 'active',
        shipping_price: variant.shipping_price === '' || variant.shipping_price == null ? null : Number(variant.shipping_price),
        weight_kg: number(variant.weight_kg), length_cm: number(variant.length_cm),
        width_cm: number(variant.width_cm), height_cm: number(variant.height_cm),
        images: Array.isArray(variant.images) ? variant.images.map(String).filter(Boolean) : []
      }));
    };

    const validate = () => {
      const found = [];
      const groupIds = new Set();
      data.options.forEach((option, oi) => {
        if (!option.id) found.push('Option group ' + (oi + 1) + ' needs an identifier.');
        if (groupIds.has(option.id)) found.push('Duplicate option group identifier: ' + option.id + '.');
        groupIds.add(option.id);
        const ids = new Set();
        if (!option.values.length) found.push((option.label || option.id || 'Option group') + ' needs at least one value.');
        option.values.forEach(value => {
          if (!value.id) found.push((option.label || option.id || 'Option group') + ' has a value without an identifier.');
          if (ids.has(value.id)) found.push('Duplicate value ' + value.id + ' in ' + (option.label || option.id) + '.');
          ids.add(value.id);
        });
      });
      const ids = new Set(), skus = new Set(), combinations = new Set();
      data.variants.forEach((variant, index) => {
        if (!variant.id) found.push('Variant ' + (index + 1) + ' needs an identifier.');
        if (ids.has(variant.id)) found.push('Duplicate variant identifier: ' + variant.id + '.');
        ids.add(variant.id);
        const sku = variant.sku.toLowerCase();
        if (!sku) found.push('Variant ' + (variant.id || index + 1) + ' needs a SKU.');
        if (sku && skus.has(sku)) found.push('Duplicate variant SKU: ' + variant.sku + '.');
        skus.add(sku);
        data.options.forEach(option => {
          const selected = variant.options[option.id];
          if (!option.values.some(value => value.id === selected)) found.push('Variant ' + (variant.id || index + 1) + ' needs a valid ' + (option.label || option.id) + '.');
        });
        const combination = keyFor(variant.options);
        if (combination && combinations.has(combination)) found.push('Duplicate option combination: ' + combination + '.');
        combinations.add(combination);
        ['price','price_adjustment','shipping_price','weight_kg','length_cm','width_cm','height_cm'].forEach(field => {
          if (variant[field] != null && !Number.isFinite(Number(variant[field]))) found.push('Variant ' + (variant.id || index + 1) + ' has an invalid ' + field.replaceAll('_', ' ') + '.');
        });
      });
      const unique = Array.from(new Set(found));
      errors.hidden = unique.length === 0;
      errors.innerHTML = unique.length ? '<strong>Fix these variant issues before saving:</strong><ul>' + unique.map(error => '<li>' + esc(error) + '</li>').join('') + '</ul>' : '';
      return unique;
    };

    const sync = () => {
      normalize();
      hidden.value = JSON.stringify(data);
      summary.textContent = data.options.length + ' option group' + (data.options.length === 1 ? '' : 's') + ' · ' + data.variants.length + ' variant' + (data.variants.length === 1 ? '' : 's');
      validate();
    };

    const valueRow = (value, oi, vi) => '<div class="mrc-option-value" data-value-index="' + vi + '">'
      + '<label><span>Value label</span><input class="uk-input" data-field="value-label" data-option="' + oi + '" data-value="' + vi + '" value="' + esc(value.label) + '"></label>'
      + '<label><span>API key</span><input class="uk-input mrc-code-input" data-field="value-id" data-option="' + oi + '" data-value="' + vi + '" data-old="' + esc(value.id) + '" value="' + esc(value.id) + '"></label>'
      + '<button type="button" class="ui-button ui-button-secondary mrc-remove-value" data-option="' + oi + '" data-value="' + vi + '" aria-label="Remove value"><i class="fa fa-trash" aria-hidden="true"></i></button></div>';

    const renderOptions = () => {
      optionList.innerHTML = data.options.length ? data.options.map((option, oi) => '<article class="mrc-option-card" data-option-index="' + oi + '">'
        + '<div class="mrc-option-card-head"><strong>Option group ' + (oi + 1) + '</strong><div>'
        + '<button type="button" class="mrc-icon-button mrc-move-option" data-option="' + oi + '" data-direction="-1" aria-label="Move option up"' + (oi === 0 ? ' disabled' : '') + '><i class="fa fa-arrow-up"></i></button>'
        + '<button type="button" class="mrc-icon-button mrc-move-option" data-option="' + oi + '" data-direction="1" aria-label="Move option down"' + (oi === data.options.length - 1 ? ' disabled' : '') + '><i class="fa fa-arrow-down"></i></button>'
        + '<button type="button" class="mrc-icon-button mrc-remove-option" data-option="' + oi + '" aria-label="Remove option group"><i class="fa fa-trash"></i></button></div></div>'
        + '<div class="mrc-option-fields"><label><span>Customer label</span><input class="uk-input" data-field="option-label" data-option="' + oi + '" value="' + esc(option.label) + '"></label>'
        + '<label><span>API key</span><input class="uk-input mrc-code-input" data-field="option-id" data-option="' + oi + '" data-old="' + esc(option.id) + '" value="' + esc(option.id) + '"></label></div>'
        + '<div class="mrc-option-values">' + option.values.map((value, vi) => valueRow(value, oi, vi)).join('') + '</div>'
        + '<button type="button" class="ui-button ui-button-secondary mrc-add-value" data-option="' + oi + '"><i class="fa fa-plus" aria-hidden="true"></i> Add value</button></article>').join('')
        : '<div class="mrc-empty-state"><strong>No option groups yet</strong><p>Add Size, Color, Material, Finish, or any other choice customers need to make.</p></div>';
    };

    const optionSelect = (variant, option, index) => '<select class="uk-select" data-field="variant-option" data-variant="' + index + '" data-option-id="' + esc(option.id) + '">'
      + '<option value="">Choose…</option>' + option.values.map(value => '<option value="' + esc(value.id) + '"' + (variant.options[option.id] === value.id ? ' selected' : '') + '>' + esc(value.label) + '</option>').join('') + '</select>';

    const advancedRow = (variant, index) => '<tr class="mrc-variant-advanced"' + (openVariant === variant.id ? '' : ' hidden') + '><td colspan="' + (data.options.length + 6) + '"><div class="mrc-advanced-grid">'
      + field('Price adjustment', 'price_adjustment', variant.price_adjustment, index, 'number', '0.01')
      + field('Shipping price', 'shipping_price', variant.shipping_price, index, 'number', '0.01')
      + field('Low-stock threshold', 'low_stock_threshold', variant.low_stock_threshold, index, 'number', '1')
      + selectField('Stock policy', 'stock_policy', variant.stock_policy, index, {deny:'Do not oversell',backorder:'Allow backorders',preorder:'Allow preorders'})
      + field('Weight (kg)', 'weight_kg', variant.weight_kg, index, 'number', '0.001')
      + field('Length (cm)', 'length_cm', variant.length_cm, index, 'number', '0.01')
      + field('Width (cm)', 'width_cm', variant.width_cm, index, 'number', '0.01')
      + field('Height (cm)', 'height_cm', variant.height_cm, index, 'number', '0.01')
      + '<label class="mrc-wide-field"><span>Image names or IDs</span><input class="uk-input" data-field="variant-images" data-variant="' + index + '" value="' + esc(variant.images.join(', ')) + '"><small>Comma-separated references from the product image field.</small></label>'
      + '</div></td></tr>';

    const field = (label, name, value, index, type = 'text', step = '') => '<label><span>' + esc(label) + '</span><input class="uk-input" type="' + type + '"' + (step ? ' step="' + step + '"' : '') + ' data-field="variant-' + name.replaceAll('_', '-') + '" data-variant="' + index + '" value="' + esc(value == null ? '' : value) + '"></label>';
    const selectField = (label, name, value, index, choices) => '<label><span>' + esc(label) + '</span><select class="uk-select" data-field="variant-' + name.replaceAll('_', '-') + '" data-variant="' + index + '">' + Object.entries(choices).map(([key, text]) => '<option value="' + key + '"' + (key === value ? ' selected' : '') + '>' + esc(text) + '</option>').join('') + '</select></label>';

    const renderVariants = () => {
      table.querySelector('thead').innerHTML = '<tr>' + data.options.map(option => '<th>' + esc(option.label || option.id) + '</th>').join('')
        + '<th>SKU</th><th>Price</th><th>Stock</th><th>Status</th><th>Details</th><th></th></tr>';
      table.querySelector('tbody').innerHTML = data.variants.length ? data.variants.map((variant, index) => '<tr data-variant-index="' + index + '">'
        + data.options.map(option => '<td data-label="' + esc(option.label || option.id) + '">' + optionSelect(variant, option, index) + '</td>').join('')
        + '<td data-label="SKU"><input class="uk-input mrc-code-input" data-field="variant-sku" data-variant="' + index + '" value="' + esc(variant.sku) + '"></td>'
        + '<td data-label="Price"><input class="uk-input" type="number" min="0" step="0.01" data-field="variant-price" data-variant="' + index + '" value="' + esc(variant.price == null ? '' : variant.price) + '" placeholder="Base"></td>'
        + '<td data-label="Stock"><input class="uk-input" type="number" step="1" data-field="variant-stock" data-variant="' + index + '" value="' + esc(variant.stock) + '"></td>'
        + '<td data-label="Status"><select class="uk-select" data-field="variant-status" data-variant="' + index + '"><option value="active"' + (variant.status === 'active' ? ' selected' : '') + '>Active</option><option value="unavailable"' + (variant.status === 'unavailable' ? ' selected' : '') + '>Unavailable</option><option value="archived"' + (variant.status === 'archived' ? ' selected' : '') + '>Archived</option></select></td>'
        + '<td data-label="Details"><button type="button" class="mrc-icon-button mrc-toggle-advanced" data-variant="' + index + '" aria-expanded="' + (openVariant === variant.id ? 'true' : 'false') + '"><i class="fa fa-sliders"></i><span class="mrc-visually-hidden">Edit advanced variant details</span></button></td>'
        + '<td><button type="button" class="mrc-icon-button mrc-remove-variant" data-variant="' + index + '" aria-label="Remove variant"><i class="fa fa-trash"></i></button></td></tr>' + advancedRow(variant, index)).join('')
        : '<tr><td colspan="' + (data.options.length + 6) + '"><div class="mrc-empty-state"><strong>No variants yet</strong><p>Generate all combinations, or add only a specific purchasable combination.</p></div></td></tr>';
    };

    const render = () => { normalize(); renderOptions(); renderVariants(); sync(); };
    const cartesian = groups => groups.reduce((rows, group) => rows.flatMap(row => group.values.map(value => Object.assign({}, row, {[group.id]: value.id}))), [{}]);
    const makeVariant = selection => {
      const suffix = Object.values(selection).map(slug).filter(Boolean).join('-');
      const baseSku = slug(root.dataset.baseSku || '').toUpperCase();
      return {id: suffix || uid('variant'), options: selection, sku: [baseSku, suffix.toUpperCase()].filter(Boolean).join('-'), price: number(root.dataset.basePrice), price_adjustment: 0, stock: 0, low_stock_threshold: 0, stock_policy: 'deny', status: 'active', shipping_price: null, weight_kg: null, length_cm: null, width_cm: null, height_cm: null, images: []};
    };

    root.addEventListener('click', event => {
      const button = event.target.closest('button');
      if (!button) return;
      if (button.classList.contains('mrc-add-option')) data.options.push({id: '', label: '', values: []});
      else if (button.classList.contains('mrc-remove-option')) {
        const index = Number(button.dataset.option), id = data.options[index].id;
        if (data.variants.some(variant => variant.options[id]) && !window.confirm('Remove this option group from every existing variant?')) return;
        data.options.splice(index, 1); data.variants.forEach(variant => delete variant.options[id]);
      } else if (button.classList.contains('mrc-move-option')) {
        const from = Number(button.dataset.option), to = from + Number(button.dataset.direction);
        if (to >= 0 && to < data.options.length) data.options.splice(to, 0, data.options.splice(from, 1)[0]);
      } else if (button.classList.contains('mrc-add-value')) {
        data.options[Number(button.dataset.option)].values.push({id: '', label: ''});
      } else if (button.classList.contains('mrc-remove-value')) {
        const oi = Number(button.dataset.option), vi = Number(button.dataset.value), id = data.options[oi].values[vi].id;
        if (data.variants.some(variant => variant.options[data.options[oi].id] === id) && !window.confirm('Variants using this value will become invalid until changed or removed. Continue?')) return;
        data.options[oi].values.splice(vi, 1);
      } else if (button.classList.contains('mrc-generate-variants')) {
        if (!data.options.length || data.options.some(option => !option.id || !option.values.length)) { validate(); return; }
        const existing = new Set(data.variants.map(variant => keyFor(variant.options)));
        cartesian(data.options).forEach(selection => { if (!existing.has(keyFor(selection))) data.variants.push(makeVariant(selection)); });
      } else if (button.classList.contains('mrc-add-variant')) {
        const selection = {}; data.options.forEach(option => { if (option.values[0]) selection[option.id] = option.values[0].id; }); data.variants.push(makeVariant(selection));
      } else if (button.classList.contains('mrc-remove-variant')) data.variants.splice(Number(button.dataset.variant), 1);
      else if (button.classList.contains('mrc-toggle-advanced')) openVariant = openVariant === data.variants[Number(button.dataset.variant)].id ? '' : data.variants[Number(button.dataset.variant)].id;
      else return;
      render();
    });

    root.addEventListener('input', event => {
      const el = event.target, fieldName = el.dataset.field;
      if (!fieldName) return;
      const oi = Number(el.dataset.option), vi = Number(el.dataset.value), ri = Number(el.dataset.variant);
      if (fieldName === 'option-label') {
        data.options[oi].label = el.value;
        if (!data.options[oi].id) {
          const generated = slug(el.value);
          data.options[oi].id = generated;
          const idInput = root.querySelector('[data-field="option-id"][data-option="' + oi + '"]');
          if (idInput) { idInput.value = generated; idInput.dataset.old = generated; }
        }
      }
      else if (fieldName === 'option-id') {
        const old = el.dataset.old, next = slug(el.value); data.options[oi].id = next;
        data.variants.forEach(variant => { if (old !== next && Object.prototype.hasOwnProperty.call(variant.options, old)) { variant.options[next] = variant.options[old]; delete variant.options[old]; } }); el.dataset.old = next;
      } else if (fieldName === 'value-label') {
        data.options[oi].values[vi].label = el.value;
        if (!data.options[oi].values[vi].id) {
          const generated = slug(el.value);
          data.options[oi].values[vi].id = generated;
          const idInput = root.querySelector('[data-field="value-id"][data-option="' + oi + '"][data-value="' + vi + '"]');
          if (idInput) { idInput.value = generated; idInput.dataset.old = generated; }
        }
      }
      else if (fieldName === 'value-id') {
        const old = el.dataset.old, next = slug(el.value), optionId = data.options[oi].id; data.options[oi].values[vi].id = next;
        data.variants.forEach(variant => { if (variant.options[optionId] === old) variant.options[optionId] = next; }); el.dataset.old = next;
      } else if (fieldName === 'variant-option') data.variants[ri].options[el.dataset.optionId] = el.value;
      else if (fieldName === 'variant-images') data.variants[ri].images = el.value.split(',').map(value => value.trim()).filter(Boolean);
      else if (fieldName.startsWith('variant-')) {
        const key = fieldName.slice(8).replaceAll('-', '_');
        data.variants[ri][key] = ['price','shipping_price','weight_kg','length_cm','width_cm','height_cm'].includes(key) ? number(el.value) : ['stock','low_stock_threshold'].includes(key) ? Number.parseInt(el.value || 0, 10) : el.value;
      }
      sync();
    });
    root.addEventListener('change', event => { if (event.target.matches('select[data-field]')) sync(); });
    render();
  }

  const start = scope => scope.querySelectorAll('.mrc-variant-editor').forEach(boot);
  document.addEventListener('DOMContentLoaded', () => start(document));
  document.addEventListener('reloaded', event => start(event.target || document));
}());
