document.addEventListener('DOMContentLoaded',()=>{
  const menu=document.querySelector('[data-menu-toggle]');
  const overlay=document.querySelector('[data-close-menu]');
  function setMenu(open){
    document.body.classList.toggle('menu-open',open);
    if(menu){menu.setAttribute('aria-expanded',String(open));menu.setAttribute('aria-label',open?'Close menu':'Open menu');}
  }
  menu?.addEventListener('click',()=>setMenu(!document.body.classList.contains('menu-open')));
  overlay?.addEventListener('click',()=>setMenu(false));
  document.addEventListener('keydown',e=>{if(e.key==='Escape')setMenu(false)});
  window.addEventListener('resize',()=>{if(window.innerWidth>850)setMenu(false)});
  document.querySelectorAll('[data-toggle-password]').forEach(button=>{
    button.addEventListener('click',()=>{
      const field=document.getElementById(button.dataset.togglePassword);
      if(!field)return;
      const show=field.type==='password';
      field.type=show?'text':'password';
      button.textContent=show?'Hide':'Show';
      button.setAttribute('aria-label',show?'Hide password':'Show password');
    });
  });
  document.querySelectorAll('[data-confirm]').forEach(form=>{
    form.addEventListener('submit',e=>{
      if(!window.confirm(form.dataset.confirm||'Are you sure?'))e.preventDefault();
    });
  });
  // Search, status filters and page navigation are handled together below.
});


/* Owner setup: show only password rules that remain unmet. */
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-owner-registration]');
  if (!form) return;

  const password = document.getElementById('owner-password');
  const confirmPassword = document.getElementById('owner-password-confirm');
  const requirements = document.getElementById('password-requirements');
  const mismatch = document.getElementById('password-match-feedback');
  const rules = {
    length: value => value.length >= 8,
    uppercase: value => /[A-Z]/.test(value),
    number: value => /[0-9]/.test(value)
  };
  function updateRequirements() {
    const value = password.value;
    let unmetCount = 0;
    for (const [ruleName, meets] of Object.entries(rules)) {
      const item = requirements.querySelector('[data-password-rule="' + ruleName + '"]');
      const missing = !meets(value);
      item.hidden = !missing;
      if (missing) unmetCount++;
    }
    // No warning for an empty password or focus alone; show only unmet rules after typing.
    requirements.hidden = value.length === 0 || unmetCount === 0;
    password.setCustomValidity(value.length && unmetCount > 0 ? 'Meet the password requirements below.' : '');
    return unmetCount === 0;
  }

  function updateConfirmation() {
    const hasInput = confirmPassword.value.length > 0;
    const different = hasInput && confirmPassword.value !== password.value;
    confirmPassword.setCustomValidity(different ? 'Passwords do not match.' : '');
    mismatch.hidden = !different;
    return !different;
  }

  password.addEventListener('input', () => {
    updateRequirements();
    updateConfirmation();
  });
  confirmPassword.addEventListener('input', updateConfirmation);
  form.addEventListener('submit', event => {
    const passwordValid = updateRequirements();
    const matchValid = updateConfirmation();
    if (!passwordValid || !matchValid) {
      event.preventDefault();
      (passwordValid ? confirmPassword : password).reportValidity();
    }
  });
  updateRequirements();
  updateConfirmation();
});


/* Accessible success/error popup shown after server-side actions. */
document.addEventListener('DOMContentLoaded', () => {
  const overlay = document.querySelector('[data-notice-popup]');
  if (!overlay) return;
  const dialog = overlay.querySelector('.notice-dialog');
  const closeButton = overlay.querySelector('[data-notice-dismiss]');
  const previousFocus = document.activeElement;
  let autoCloseId;
  const close = () => {
    if (overlay.hidden) return;
    overlay.hidden = true;
    document.body.classList.remove('notice-is-open');
    clearTimeout(autoCloseId);
    if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus();
    document.dispatchEvent(new CustomEvent('notice-closed'));
  };
  overlay.hidden = false;
  document.body.classList.add('notice-is-open');
  // Focus the dialog first; keyboard Tab moves to the OK button without a distracting outline on open.
  dialog?.focus({preventScroll:true});
  closeButton?.addEventListener('click', close);
  overlay.addEventListener('click', event => {if (event.target === overlay) close();});
  overlay.addEventListener('keydown', event => {
    if (event.key === 'Escape') {event.preventDefault();close();}
    if (event.key === 'Tab') {
      // Dialog has a single interactive control; do not move keyboard focus behind it.
      event.preventDefault();
      closeButton?.focus();
    }
  });
  if (overlay.dataset.noticeType === 'success') autoCloseId = setTimeout(close, 4600);
});


/* Select the exact batch only for waste/damage; normal usage stays automatic FEFO. */
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-stock-out-form]');
  if (!form) return;
  const item = form.querySelector('select[name="item_id"]');
  const reason = form.querySelector('select[name="reason"]');
  const group = form.querySelector('[data-affected-batch]');
  const batch = form.querySelector('select[name="lot_id"]');
  if (!item || !reason || !group || !batch) return;

  const options = Array.from(batch.options).filter(option => option.value !== '');
  function syncBatch() {
    const needsBatch = ['Waste / expired', 'Damaged'].includes(reason.value);
    const currentItem = item.value;
    for (const option of options) {
      const matches = option.dataset.batchItem === currentItem;
      option.hidden = !matches;
      option.disabled = !matches;
    }
    if (!options.some(option => option.value === batch.value && !option.disabled)) batch.value = '';
    group.hidden = !needsBatch;
    batch.disabled = !needsBatch;
    batch.required = needsBatch;
  }
  item.addEventListener('change', syncBatch);
  reason.addEventListener('change', syncBatch);
  syncBatch();
});


/* Accessible Add/Edit Inventory modal shared by new and existing items. */
document.addEventListener('DOMContentLoaded', () => {
  const backdrop = document.querySelector('[data-item-modal]');
  if (!backdrop) return;
  const dialog = backdrop.querySelector('.item-dialog');
  const form = backdrop.querySelector('[data-item-form]');
  const heading = backdrop.querySelector('[data-item-dialog-title]');
  const help = backdrop.querySelector('[data-item-dialog-help]');
  const submit = backdrop.querySelector('[data-item-submit]');
  const archive = backdrop.querySelector('[data-item-toggle]');
  const unitSelect = form.querySelector('select[name="unit"]');
  const unitBackup = form.querySelector('[data-locked-unit]');
  const unitNote = form.querySelector('[data-unit-lock-message]');
  const field = name => form.querySelector('[name="' + name + '"]');
  let lastTrigger = null;

  function openItem(trigger = null, restore = null) {
    lastTrigger = trigger;
    const dataset = trigger?.dataset || {};
    const id = Number(dataset.itemId || restore?.id || 0);
    const editing = id > 0;

    form.reset();
    field('action').value = 'item_save';
    field('id').value = editing ? String(id) : '0';
    field('name').value = dataset.itemName || '';
    field('sku').value = dataset.itemSku || '';
    field('category_id').value = dataset.itemCategory || '';
    unitSelect.value = dataset.itemUnit || '';
    field('min_level').value = dataset.itemMin || '0';

    // Preserve typed inputs after a rejected server-side save.
    if (restore) {
      for (const name of ['name', 'sku', 'category_id', 'min_level']) {
        if (Object.hasOwn(restore, name)) field(name).value = String(restore[name] ?? '');
      }
      if (!editing && Object.hasOwn(restore, 'unit')) unitSelect.value = String(restore.unit ?? '');
    }

    const locked = editing && dataset.itemHasHistory === '1';
    unitSelect.disabled = locked;
    unitBackup.disabled = !locked;
    unitBackup.value = locked ? unitSelect.value : '';
    unitNote.hidden = !locked;
    const active = dataset.itemActive !== '0';
    const hasStock = dataset.itemHasStock === '1';
    heading.textContent = editing ? 'Edit Inventory Item' : 'Add New Item';
    help.textContent = !editing
      ? 'Set up the item details. Add its quantity later using Stock In.'
      : hasStock
        ? 'Edit the details below. Archive is available once all stock has been cleared.'
        : 'Update the item details below. Stock quantities change through Stock In and Stock Out.';
    submit.textContent = editing ? 'Save Changes' : 'Add Item';
    submit.disabled = false;
    archive.hidden = !editing;
    archive.disabled = editing && active && hasStock;
    archive.textContent = active ? 'Archive Item' : 'Restore Item';
    archive.dataset.active = active ? '1' : '0';
    backdrop.hidden = false;
    document.body.classList.add('item-modal-open');
    dialog.focus({preventScroll:true});
    field('name').focus({preventScroll:true});
  }

  function closeItem() {
    if (backdrop.hidden) return;
    backdrop.hidden = true;
    document.body.classList.remove('item-modal-open');
    if (lastTrigger?.isConnected) lastTrigger.focus({preventScroll:true});
  }

  document.querySelectorAll('[data-item-modal-open]').forEach(trigger => {
    trigger.addEventListener('click', () => openItem(trigger));
  });
  backdrop.querySelectorAll('[data-item-modal-close]').forEach(button => {
    button.addEventListener('click', closeItem);
  });
  backdrop.addEventListener('click', event => {
    if (event.target === backdrop) closeItem();
  });
  document.addEventListener('keydown', event => {
    if (backdrop.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeItem();
      return;
    }
    if (event.key !== 'Tab') return;
    const nodes = [...dialog.querySelectorAll('button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled])')]
      .filter(node => node.getClientRects().length > 0);
    if (!nodes.length) return;
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  archive.addEventListener('click', event => {
    const active = archive.dataset.active === '1';
    const message = active
      ? 'Archive this item? It will no longer be available for new stock movements.'
      : 'Restore this item to the active inventory?';
    if (!window.confirm(message)) {
      event.preventDefault();
      return;
    }
    field('action').value = 'item_toggle';
  });

  form.addEventListener('submit', () => {
    if (field('action').value === 'item_toggle') return;
    submit.disabled = true;
    submit.textContent = 'Saving…';
  });

  const params = new URLSearchParams(window.location.search);
  let retry = null;
  try { retry = JSON.parse(backdrop.dataset.itemRetry || 'null'); } catch { retry = null; }
  const legacyId = Number(params.get('edit') || 0);
  const targetId = Number(retry?.id || legacyId || 0);
  const trigger = targetId > 0
    ? [...document.querySelectorAll('[data-item-modal-open][data-item-id]')].find(button => Number(button.dataset.itemId) === targetId)
    : null;
  if (retry || trigger || params.has('new')) {
    const show = () => openItem(trigger, retry);
    const notice = document.querySelector('[data-notice-popup]');
    if (notice && !notice.hidden) document.addEventListener('notice-closed', show, {once:true});
    else show();
  }
});




/* Owner category manager: create, rename and delete from one compact modal. */
document.addEventListener('DOMContentLoaded', () => {
  const layer = document.querySelector('[data-category-modal]');
  const openButton = document.querySelector('[data-category-open]');
  if (!layer || !openButton) return;
  const dialog = layer.querySelector('.category-dialog');
  const normal = layer.querySelector('[data-category-normal-view]');
  const confirmation = layer.querySelector('[data-category-confirm-view]');
  const form = layer.querySelector('[data-category-form]');
  const nameInput = form.querySelector('input[name="name"]');
  const idInput = form.querySelector('input[name="id"]');
  const actionInput = form.querySelector('input[name="action"]');
  const label = form.querySelector('[data-category-form-label]');
  const submit = form.querySelector('[data-category-submit]');
  const cancelEdit = form.querySelector('[data-category-cancel-edit]');
  const closeButton = layer.querySelector('[data-category-close]');
  const deleteForm = layer.querySelector('.category-confirm-actions');
  const confirmText = layer.querySelector('[data-category-confirm-text]');
  const confirmId = deleteForm.querySelector('input[name="id"]');
  const cancelDelete = layer.querySelector('[data-category-cancel-delete]');
  let lastFocused = null;

  function resetEditor() {
    form.reset();
    idInput.value = '0';
    actionInput.value = 'category';
    label.textContent = 'New Category';
    submit.textContent = 'Add Category';
    cancelEdit.hidden = true;
  }
  function startEdit(row, retryName = null) {
    actionInput.value = 'category_edit';
    idInput.value = row.dataset.categoryId;
    nameInput.value = retryName === null ? row.dataset.categoryName : retryName;
    label.textContent = 'Edit Category';
    submit.textContent = 'Save Changes';
    cancelEdit.hidden = false;
    nameInput.focus({preventScroll: true});
    nameInput.scrollIntoView({block: 'nearest'});
    nameInput.select();
  }
  function openManager() {
    lastFocused = document.activeElement;
    normal.hidden = false;
    confirmation.hidden = true;
    resetEditor();
    layer.hidden = false;
    document.body.classList.add('item-modal-open');
    nameInput.focus({preventScroll: true});
  }
  function closeManager() {
    if (layer.hidden) return;
    layer.hidden = true;
    document.body.classList.remove('item-modal-open');
    if (lastFocused?.isConnected) lastFocused.focus({preventScroll: true});
  }
  openButton.addEventListener('click', openManager);
  closeButton.addEventListener('click', closeManager);
  layer.addEventListener('click', event => {
    if (event.target === layer) closeManager();
  });
  layer.querySelectorAll('[data-category-edit]').forEach(button => {
    button.addEventListener('click', () => startEdit(button.closest('[data-category-row]')));
  });
  cancelEdit.addEventListener('click', () => {
    resetEditor();
    nameInput.focus();
  });
  layer.querySelectorAll('[data-category-delete]').forEach(button => {
    button.addEventListener('click', () => {
      const row = button.closest('[data-category-row]');
      if (button.disabled || Number(row.dataset.categoryCount) > 0) return;
      confirmId.value = row.dataset.categoryId;
      confirmText.textContent = 'Remove "' + row.dataset.categoryName + '" from categories?';
      normal.hidden = true;
      confirmation.hidden = false;
      cancelDelete.focus({preventScroll: true});
    });
  });
  cancelDelete.addEventListener('click', () => {
    confirmation.hidden = true;
    normal.hidden = false;
    const target = layer.querySelector('[data-category-row][data-category-id="' + confirmId.value + '"] [data-category-delete]');
    (target || nameInput).focus({preventScroll: true});
  });
  document.addEventListener('keydown', event => {
    if (layer.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      if (!confirmation.hidden) cancelDelete.click();
      else closeManager();
      return;
    }
    if (event.key !== 'Tab') return;
    const controls = [...dialog.querySelectorAll('button:not([disabled]),input:not([type="hidden"])')]
      .filter(el => !el.hidden && el.getClientRects().length > 0);
    if (!controls.length) return;
    if (event.shiftKey && document.activeElement === controls[0]) {
      event.preventDefault();
      controls[controls.length - 1].focus();
    } else if (!event.shiftKey && document.activeElement === controls[controls.length - 1]) {
      event.preventDefault();
      controls[0].focus();
    }
  });
  let retry = null;
  try {retry = JSON.parse(layer.dataset.categoryRetry || 'null');} catch {}
  const params = new URLSearchParams(window.location.search);
  if (params.has('categories') || retry) {
    const reopen = () => {
      openManager();
      if (retry) {
        const row = layer.querySelector('[data-category-row][data-category-id="' + (Number(retry.id) || 0) + '"]');
        if (retry.action === 'category_edit' && row) startEdit(row, String(retry.name || ''));
        else nameInput.value = String(retry.name || '');
      }
    };
    const alert = document.querySelector('[data-notice-popup]');
    if (alert && !alert.hidden) document.addEventListener('notice-closed', reopen, {once: true});
    else reopen();
  }
});


/* Owner stock count reconciliation: filter batches and preview the exact correction. */
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-adjust-stock-form]');
  if (!form) return;
  const item = form.querySelector('select[name="item_id"]');
  const batch = form.querySelector('select[name="lot_id"]');
  const actual = form.querySelector('input[name="actual_quantity"]');
  const currentBox = form.querySelector('[data-adjust-current]');
  const currentLabel = form.querySelector('[data-adjust-recorded]');
  const differenceBox = form.querySelector('[data-adjust-result]');
  const differenceLabel = form.querySelector('[data-adjust-difference]');
  const save = form.querySelector('[data-adjust-save]');
  const options = [...batch.options].filter(option => option.value);
  const showQty = number => Number(number.toFixed(3)).toLocaleString('en-US',{maximumFractionDigits:3});

  function refresh() {
    for (const option of options) {
      const allowed = option.dataset.adjustItem === item.value;
      option.hidden = !allowed;
      option.disabled = !allowed;
    }
    const picked = batch.selectedOptions[0];
    if (!picked?.value || picked.disabled) batch.value = '';
    const chosen = batch.selectedOptions[0];
    const hasBatch = Boolean(chosen?.value && !chosen.disabled);
    currentBox.hidden = !hasBatch;
    differenceBox.hidden = true;
    save.disabled = false;
    if (!hasBatch) return;
    const current = Number(chosen.dataset.adjustQuantity || 0);
    const unit = chosen.dataset.adjustUnit || '';
    currentLabel.textContent = showQty(current) + ' ' + unit;
    const raw = actual.value.trim();
    if (!raw || !/^\d{1,9}(?:\.\d{1,3})?$/.test(raw)) return;
    const count = Number(raw);
    const diff = Number((count-current).toFixed(3));
    differenceBox.hidden = false;
    differenceLabel.textContent = diff === 0
      ? 'No change'
      : (diff > 0 ? '+' : '−') + showQty(Math.abs(diff)) + ' ' + unit;
    differenceBox.dataset.direction = diff > 0 ? 'increase' : (diff < 0 ? 'decrease' : 'none');
    save.disabled = diff === 0;
  }
  item.addEventListener('change', refresh);
  batch.addEventListener('change', refresh);
  actual.addEventListener('input', refresh);
  refresh();
});


/* Progressive-enhancement searchable selects for stock forms.
   The real <select name=...> stays the submitted source of truth. */
document.addEventListener('DOMContentLoaded', () => {
  const selects = [...document.querySelectorAll('select[data-searchable-select]')];
  if (!selects.length) return;
  let sequence = 0;
  const allWidgets = [];

  for (const select of selects) {
    const host = select.closest('label');
    if (!host) continue;
    const title = host.childNodes[0]?.textContent?.trim() || select.name.replaceAll('_', ' ');
    const widget = document.createElement('div');
    widget.className = 'search-select';
    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'search-select-input';
    search.autocomplete = 'off';
    search.spellcheck = false;
    search.placeholder = select.dataset.searchPlaceholder || 'Search and select...';
    search.setAttribute('aria-label', title);
    search.setAttribute('role', 'combobox');
    search.setAttribute('aria-autocomplete','list');
    search.setAttribute('aria-expanded','false');
    search.setAttribute('aria-haspopup','listbox');
    const list = document.createElement('div');
    list.className = 'search-select-options';
    list.id = 'search-select-list-' + (++sequence);
    list.setAttribute('role','listbox');
    list.hidden = true;
    search.setAttribute('aria-controls',list.id);
    const hint = document.createElement('span');
    hint.className = 'search-select-hint';
    hint.hidden = true;
    widget.append(search, list, hint);
    // Keep the interactive search outside <label>: pointer clicks on options
    // must not be forwarded to the original hidden <select>.
    const field = document.createElement('div');
    field.className = 'search-select-field';
    host.parentNode.insertBefore(field,host);
    field.append(host,widget);
    search.id = 'search-select-input-' + sequence;
    host.htmlFor = search.id;
    if (host.hasAttribute('data-affected-batch')) {
      field.hidden = host.hidden;
      new MutationObserver(() => {field.hidden = host.hidden;})
        .observe(host,{attributes:true,attributeFilter:['hidden']});
    }
    select.classList.add('native-searchable');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden','true');
    const required = select.required;
    select.required = false;
    const original = [...select.options].filter(option => option.value !== '');
    let activeIndex = -1;
    let shown = [];
    let open = false;
    let wasTyping = false;
    let suppressFocusOpen = false;

    const availableOptions = () => original.filter(o => !o.disabled && !o.hidden);
    const labelOf = option => option.dataset.searchPrimary?.trim()
      || option.textContent.trim().replace(/\s*\([^()]*\)\s*$/,'').trim();
    const secondaryOf = option => option.dataset.searchSecondary?.trim()
      || (option.textContent.trim().match(/\(([^()]*)\)\s*$/)?.[1] || '');
    function setValid() {
      const conditionalBatch = select.name === 'lot_id' && select.closest('[data-affected-batch]');
      const mandatory = required || (conditionalBatch && !conditionalBatch.hidden);
      // Stock-Out changes native required dynamically; visible search takes over validation.
      select.required = false;
      const bad = mandatory && !select.disabled && !select.value;
      search.setCustomValidity(bad ? 'Choose an option from the list.' : '');
    }
    function render(filter = '') {
      const query = filter.trim().toLocaleLowerCase();
      shown = availableOptions().filter(option =>
        !query || (labelOf(option)+' '+secondaryOf(option)+' '+option.textContent).toLocaleLowerCase().includes(query)
      );
      list.replaceChildren();
      activeIndex = shown.length ? 0 : -1;
      if (!shown.length) {
        const empty = document.createElement('p');
        empty.className = 'search-select-empty';
        empty.textContent = original.length && !availableOptions().length
          ? 'Select an ingredient first.' : 'No matching results';
        list.append(empty);
      } else {
        shown.forEach((option, index) => {
          const entry = document.createElement('button');
          entry.type='button';
          entry.className='search-select-option';
          entry.setAttribute('role','option');
          entry.setAttribute('aria-selected', String(option.value===select.value));
          entry.id = list.id+'-'+index;
          const primary = document.createElement('span');
          primary.className='search-select-primary';
          primary.textContent=labelOf(option);
          entry.append(primary);
          const meta=secondaryOf(option);
          if (meta) {
            const secondary = document.createElement('small');
            secondary.className='search-select-secondary';
            secondary.textContent=meta;
            entry.append(secondary);
          }
          if (index===activeIndex)entry.classList.add('is-active');
          entry.addEventListener('click', () => choose(option));
          list.append(entry);
        });
      }
      highlight(activeIndex, false);
    }
    function highlight(index, scroll = true) {
      const buttons=[...list.querySelectorAll('.search-select-option')];
      if (!buttons.length) {
        activeIndex=-1;
        search.removeAttribute('aria-activedescendant');
        return;
      }
      activeIndex=(index + buttons.length)%buttons.length;
      buttons.forEach((button,i)=>button.classList.toggle('is-active',i===activeIndex));
      const target=buttons[activeIndex];
      search.setAttribute('aria-activedescendant',target.id);
      if(scroll)target.scrollIntoView({block:'nearest'});
    }
    function closeList() {
      open=false;
      list.hidden=true;
      search.setAttribute('aria-expanded','false');
      search.removeAttribute('aria-activedescendant');
    }
    function openList(filter='') {
      if(search.disabled)return;
      allWidgets.forEach(w=>{if(w.select!==select) w.close();});
      open=true;
      render(filter);
      // Keep menus inside the mobile viewport when the input is near the bottom.
      const rect=widget.getBoundingClientRect();
      list.classList.toggle('open-upwards',window.innerHeight-rect.bottom<230 && rect.top>250);
      list.hidden=false;
      search.setAttribute('aria-expanded','true');
    }
    function refreshFromNative() {
      const available=availableOptions();
      const enabled = !select.disabled && (available.length>0 || select.name==='item_id');
      search.disabled = !enabled;
      if(!enabled)closeList();
      const selected=select.selectedOptions[0];
      if(!wasTyping)search.value=selected?.value ? labelOf(selected) : '';
      const meta=selected?.value ? secondaryOf(selected) : '';
      hint.textContent=meta;
      hint.hidden=!meta || !enabled;
      setValid();
      // Do not rebuild an open option list while pointer click is in progress;
      // detaching its buttons between pointerdown and click loses the selection.
    }
    function choose(option) {
      wasTyping=false;
      select.value=option.value;
      search.value=labelOf(option);
      closeList();
      setValid();
      select.dispatchEvent(new Event('change',{bubbles:true}));
      refreshFromNative();
      // Other dependent selects may have been reset in the preceding change handler.
      allWidgets.forEach(w=>w.sync());
      suppressFocusOpen=true;
      search.focus({preventScroll:true});
      suppressFocusOpen=false;
    }

    search.addEventListener('focus', () => {
      if (suppressFocusOpen) return;
      wasTyping=false;
      openList();
      if (select.value) search.select();
    });
    search.addEventListener('click', () => {if(!open)openList();});
    search.addEventListener('input', () => {
      wasTyping=true;
      if(select.value) {
        select.value='';
        select.dispatchEvent(new Event('change',{bubbles:true}));
      }
      hint.hidden=true;
      setValid();
      openList(search.value);
    });
    search.addEventListener('keydown',event => {
      if (event.key==='ArrowDown' || event.key==='ArrowUp') {
        event.preventDefault();
        if(!open)openList('');
        else highlight(activeIndex+(event.key==='ArrowDown'?1:-1));
      } else if(event.key==='Enter' && open) {
        event.preventDefault();
        if(activeIndex>=0&&shown[activeIndex])choose(shown[activeIndex]);
      } else if(event.key==='Escape' && open) {
        event.preventDefault();
        wasTyping=false;
        refreshFromNative();
        closeList();
      } else if (event.key==='Tab') {
        closeList();
      }
    });
    search.addEventListener('invalid',event => {
      event.preventDefault();
      search.focus({preventScroll:true});
      openList();
    });
    select.addEventListener('change', refreshFromNative);
    select.closest('form')?.addEventListener('change', () => {
      // Item/reason changes can disable other batch options without a batch change event.
      allWidgets.forEach(w=>w.sync());
    });
    document.addEventListener('pointerdown',event => {
      if(!widget.contains(event.target))closeList();
    });
    allWidgets.push({select,close:closeList,sync:refreshFromNative});
    refreshFromNative();
    const observer=new MutationObserver(()=>refreshFromNative());
    observer.observe(select,{attributes:true,attributeFilter:['disabled','required']});
  }
});


/* Shared pagination: 10 rows on desktop, 5 rows on mobile. */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-page-scope]').forEach(scope => {
    const collection=scope.querySelector('[data-page-items]');
    const controls=scope.querySelector('[data-page-controls]');
    if (!collection || !controls) return;
    const isTable=collection.matches('table');
    const rows=[...collection.querySelectorAll(isTable?'tbody > tr':'.staff-row')];
    const input=scope.querySelector('[data-filter-input]');
    const status=scope.querySelector('[data-filter-status]');
    const empty=scope.querySelector('[data-empty-table]');
    const compactPages=window.matchMedia('(max-width:620px)');
    let size=compactPages.matches?5:10;
    let page=0, matches=[];
    const summary=document.createElement('span');
    summary.className='pagination-summary';
    const pages=document.createElement('div');
    pages.className='pagination-actions';
    const previous=document.createElement('button');
    previous.type='button';previous.className='pagination-button';previous.textContent='Previous';
    previous.setAttribute('aria-label','Previous page');
    const current=document.createElement('span');
    current.className='pagination-current';
    current.setAttribute('aria-live','polite');
    const next=document.createElement('button');
    next.type='button';next.className='pagination-button';next.textContent='Next';
    next.setAttribute('aria-label','Next page');
    pages.append(previous,current,next);
    controls.replaceChildren(summary,pages);

    function apply(reset=false) {
      if(reset)page=0;
      const q=input?.value.trim().toLocaleLowerCase()||'';
      const chosen=status?.value||'all';
      matches=rows.filter(row=>
        (!q||(row.dataset.search||row.textContent.toLocaleLowerCase()).includes(q)) &&
        (chosen==='all'||row.dataset.status===chosen)
      );
      const pageCount=Math.max(1,Math.ceil(matches.length/size));
      page=Math.min(page,pageCount-1);
      const visible=new Set(matches.slice(page*size,(page+1)*size));
      rows.forEach(row=>{
        const show=visible.has(row);
        row.hidden=!show;
        row.style.display=show?'':'none';
      });
      if(empty)empty.hidden=matches.length>0 || (rows.length===0 && !!scope.querySelector('.staff-empty'));
      controls.hidden=matches.length<=size;
      if(matches.length>size) {
        const first=page*size+1;
        summary.textContent='Showing '+first+'–'+Math.min((page+1)*size,matches.length)+' of '+matches.length;
        current.textContent=(page+1)+' / '+pageCount;
        previous.disabled=page===0;
        next.disabled=page>=pageCount-1;
      }
    }
    previous.addEventListener('click',()=>{if(page>0){page--;apply();}});
    next.addEventListener('click',()=>{if((page+1)*size<matches.length){page++;apply();}});
    input?.addEventListener('input',()=>apply(true));
    status?.addEventListener('change',()=>apply(true));
    compactPages.addEventListener('change',event=>{size=event.matches?5:10;apply(true);});
    if(window.matchMedia) {
      window.addEventListener('beforeprint',()=>{
        // Print all matching records instead of only the current onscreen page.
        rows.forEach(row=>{
          const show=matches.includes(row);
          row.hidden=!show;
          row.style.display=show?'':'none';
        });
      });
      window.addEventListener('afterprint',()=>apply());
    }
    apply();
  });
});

/* Compact staff account creation modal, with accessible keyboard behavior. */
document.addEventListener('DOMContentLoaded', () => {
  const layer=document.querySelector('[data-staff-modal]');
  if(!layer)return;
  const form=layer.querySelector('[data-staff-form]');
  const dialog=layer.querySelector('.staff-dialog');
  const openers=[...document.querySelectorAll('[data-staff-open]')];
  const closers=[...layer.querySelectorAll('[data-staff-close]')];
  let focusBefore=null;
  function open(reset=true) {
    if(reset)form.reset();
    focusBefore=document.activeElement;
    layer.hidden=false;
    document.body.classList.add('item-modal-open');
    form.querySelector('input[name="name"]').focus({preventScroll:true});
  }
  function close() {
    if(layer.hidden)return;
    layer.hidden=true;
    document.body.classList.remove('item-modal-open');
    if(focusBefore?.isConnected)focusBefore.focus({preventScroll:true});
  }
  openers.forEach(button=>button.addEventListener('click',()=>open()));
  closers.forEach(button=>button.addEventListener('click',close));
  layer.addEventListener('click',event=>{if(event.target===layer)close();});
  document.addEventListener('keydown',event=>{
    if(layer.hidden)return;
    if(event.key==='Escape'){event.preventDefault();close();return;}
    if(event.key!=='Tab')return;
    const available=[...dialog.querySelectorAll('input:not([type=hidden]),button:not([disabled])')]
      .filter(element=>!element.hidden&&element.getClientRects().length>0);
    if(!available.length)return;
    if(event.shiftKey&&document.activeElement===available[0]){
      event.preventDefault();available[available.length-1].focus();
    } else if(!event.shiftKey&&document.activeElement===available[available.length-1]){
      event.preventDefault();available[0].focus();
    }
  });
  let retry=null;
  try{retry=JSON.parse(layer.dataset.staffRetry||'null');}catch{}
  const params=new URLSearchParams(window.location.search);
  if(retry||params.get('add')==='1') {
    const reopen=()=>{
      open(false);
      if(retry){
        form.querySelector('input[name="name"]').value=retry.name||'';
        form.querySelector('input[name="email"]').value=retry.email||'';
      }
      // Password is intentionally never retained after a failed submission.
      form.querySelector('input[name="password"]').value='';
    };
    const notice=document.querySelector('[data-notice-popup]');
    if(notice&&!notice.hidden)document.addEventListener('notice-closed',reopen,{once:true});
    else reopen();
  }
});


/* One-click stock submission guard for slow connections and accidental double-taps. */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form[data-lock-submit]').forEach(form => {
    let submitting=false;
    form.addEventListener('submit',event => {
      if(submitting){event.preventDefault();return;}
      if(!form.checkValidity())return;
      submitting=true;
      const button=form.querySelector('button[type="submit"],button:not([type])');
      if(button){button.disabled=true;button.textContent='Saving...';}
    });
  });
});

/* Password change for both Owner and Staff, with only unmet rules shown. */
document.addEventListener('DOMContentLoaded', () => {
  const layer=document.querySelector('[data-password-modal]');
  const triggers=[...document.querySelectorAll('[data-password-open]')];
  if(!layer||!triggers.length)return;
  const dialog=layer.querySelector('.account-password-dialog');
  const form=layer.querySelector('[data-password-form]');
  const current=form.querySelector('input[name="current_password"]');
  const replacement=form.querySelector('input[name="new_password"]');
  const confirm=form.querySelector('input[name="confirm_password"]');
  const rules=layer.querySelector('[data-password-rules]');
  const mismatch=layer.querySelector('[data-password-mismatch]');
  let focusBefore=null;
  function validate(show=false) {
    const pw=replacement.value;
    const checks={
      length:pw.length>=8,
      uppercase:/[A-Z]/.test(pw),
      number:/\d/.test(pw)
    };
    const missing=Object.values(checks).some(value=>!value);
    rules.hidden=!show||!pw||!missing;
    rules.querySelectorAll('[data-rule]').forEach(line=>{
      line.hidden=checks[line.dataset.rule];
    });
    const unmatched=confirm.value.length>0 && pw!==confirm.value;
    mismatch.hidden=!unmatched;
    confirm.setCustomValidity(unmatched?'Passwords do not match.':'');
    replacement.setCustomValidity(pw.length>0&&missing?'Password does not meet all requirements.':'');
    return !missing&&!unmatched;
  }
  function open() {
    focusBefore=document.activeElement;
    if(document.body.classList.contains('menu-open')){
      document.body.classList.remove('menu-open');
      const menu=document.querySelector('[data-menu-toggle]');
      if(menu)menu.setAttribute('aria-expanded','false');
      focusBefore=menu||focusBefore;
    }
    form.reset();
    replacement.setCustomValidity('');
    confirm.setCustomValidity('');
    validate(false);
    layer.hidden=false;
    document.body.classList.add('item-modal-open');
    current.focus({preventScroll:true});
  }
  function close() {
    if(layer.hidden)return;
    layer.hidden=true;
    document.body.classList.remove('item-modal-open');
    if(focusBefore?.isConnected)focusBefore.focus({preventScroll:true});
  }
  triggers.forEach(trigger=>trigger.addEventListener('click',open));
  layer.querySelectorAll('[data-password-close]').forEach(button=>button.addEventListener('click',close));
  layer.addEventListener('click',event=>{if(event.target===layer)close();});
  replacement.addEventListener('input',()=>validate(true));
  confirm.addEventListener('input',()=>validate(true));
  form.addEventListener('submit',event=>{
    if(!validate(true))event.preventDefault();
  });
  document.addEventListener('keydown',event=>{
    if(layer.hidden)return;
    if(event.key==='Escape'){event.preventDefault();close();return;}
    if(event.key!=='Tab')return;
    const elements=[...dialog.querySelectorAll('input:not([type=hidden]),button:not([disabled])')]
      .filter(node=>!node.hidden&&node.getClientRects().length>0);
    if(!elements.length)return;
    if(event.shiftKey&&document.activeElement===elements[0]){
      event.preventDefault();elements[elements.length-1].focus();
    } else if(!event.shiftKey&&document.activeElement===elements[elements.length-1]){
      event.preventDefault();elements[0].focus();
    }
  });
  if(layer.dataset.passwordRetry==='1'||new URLSearchParams(location.search).has('change_password')){
    const reopen=()=>open();
    const notification=document.querySelector('[data-notice-popup]');
    if(notification&&!notification.hidden)document.addEventListener('notice-closed',reopen,{once:true});
    else open();
  }
});


/* Confirmation dialog for Sign Out; the existing POST/CSRF form remains authoritative. */
document.addEventListener('DOMContentLoaded', () => {
  const form=document.querySelector('[data-signout-form]');
  const layer=document.querySelector('[data-signout-modal]');
  if(!form||!layer)return;
  const dialog=layer.querySelector('.signout-dialog');
  const cancel=layer.querySelector('[data-signout-cancel]');
  const confirm=layer.querySelector('[data-signout-confirm]');
  let permitted=false;
  let focusBefore=null;

  function close() {
    if(layer.hidden)return;
    layer.hidden=true;
    document.body.classList.remove('item-modal-open');
    if(focusBefore?.isConnected)focusBefore.focus({preventScroll:true});
  }
  function open() {
    focusBefore=document.activeElement;
    if(document.body.classList.contains('menu-open')){
      document.body.classList.remove('menu-open');
      const menu=document.querySelector('[data-menu-toggle]');
      if(menu){
        menu.setAttribute('aria-expanded','false');
        menu.setAttribute('aria-label','Open menu');
        focusBefore=menu;
      }
    }
    layer.hidden=false;
    document.body.classList.add('item-modal-open');
    cancel.focus({preventScroll:true});
  }
  form.addEventListener('submit',event=>{
    if(permitted)return;
    event.preventDefault();
    open();
  });
  confirm.addEventListener('click',()=>{
    permitted=true;
    confirm.disabled=true;
    confirm.textContent='Signing Out...';
    form.requestSubmit();
  });
  cancel.addEventListener('click',close);
  layer.addEventListener('click',event=>{if(event.target===layer)close();});
  document.addEventListener('keydown',event=>{
    if(layer.hidden)return;
    if(event.key==='Escape'){event.preventDefault();close();return;}
    if(event.key!=='Tab')return;
    if(event.shiftKey && document.activeElement===cancel){
      event.preventDefault();confirm.focus();
    }else if(!event.shiftKey && document.activeElement===confirm){
      event.preventDefault();cancel.focus();
    }
  });
});

/* Staff's temporary password has the same conditional requirements as Owner accounts. */
document.addEventListener('DOMContentLoaded', () => {
  const form=document.querySelector('[data-staff-form]');
  const input=form?.querySelector('#staff-temp-password');
  const rules=form?.querySelector('[data-staff-password-rules]');
  if(!input||!rules)return;
  function validate() {
    const text=input.value;
    const met={
      length:text.length>=8,
      uppercase:/[A-Z]/.test(text),
      number:/[0-9]/.test(text)
    };
    for(const line of rules.querySelectorAll('[data-rule]')){
      line.hidden=met[line.dataset.rule]===true;
    }
    rules.hidden=text.length===0 || Object.values(met).every(Boolean);
    input.setCustomValidity(text.length>0&&!rules.hidden
      ?'Meet the password requirements below.':'');
  }
  input.addEventListener('input',validate);
  form.addEventListener('reset',()=>{
    input.setCustomValidity('');
    rules.hidden=true;
  });
  form.addEventListener('submit',validate);
  validate();
});
