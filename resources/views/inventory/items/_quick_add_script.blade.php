{{-- Quick-add dialogs (supplier, category, sub-category, purity) shared by the retailer item create and edit
     pages. Included inside their <script>; $refreshDropdown names the page's dropdown refresher. --}}
        // ── Quick-add dialogs: one explicit state each (audit M6) ───────────────
        // While a dialog's save is on its way it stays open and cannot send twice: closing it then let the cashier
        // reopen it (button enabled again), save a duplicate, and have the late answer close the new dialog and
        // change the picker underneath.
        const quickAdd = {
            saving(id) { return document.getElementById(id)?.dataset.saving === '1'; },
            begin(id) {
                const modal = document.getElementById(id);
                if (!modal || modal.dataset.saving === '1') return false;
                modal.dataset.saving = '1';
                return true;
            },
            end(id) { const modal = document.getElementById(id); if (modal) delete modal.dataset.saving; },
        };

        // "+ New …" and "Custom…" options open a dialog; they are not a choice (audit M6). Choosing one puts the
        // select back on what it showed, so the screen and the hidden fields saved with it never disagree (a blank
        // supplier picker used to keep the old vendor_id).
        const ACTION_OPTIONS = ['__new_category__', '__new_sub_category__', '__new_supplier__', '__custom__'];
        function noteChoice(select) {
            if (select && !ACTION_OPTIONS.includes(select.value)) select.dataset.chosen = select.value;
        }
        function undoActionOption(select) {
            select.value = select.dataset.chosen ?? '';
            {{ $refreshDropdown }}(select);
        }
        ['category', 'sub_category', 'supplier_picker', 'purity'].forEach((id) => {
            const select = document.getElementById(id);
            noteChoice(select);
            select?.addEventListener('change', () => noteChoice(select));
        });

        // ── New supplier modal ─────────────────────────────────────────────────

        function openNewSupplierModal() {
            document.getElementById('newSupplierInput').value = '';
            document.getElementById('newSupplierError').classList.add('hidden');
            document.getElementById('newSupplierError').textContent = '';
            document.getElementById('newSupplierConfirm').disabled = false;
            document.getElementById('newSupplierConfirm').textContent = 'Add & Use';
            // Default to vendor tab
            selectSupplierType('vendor');
            document.getElementById('newSupplierModal').style.display = 'flex';
            document.getElementById('newSupplierInput').focus();
        }

        function closeNewSupplierModal(force = false) {
            if (force !== true && quickAdd.saving('newSupplierModal')) return;
            document.getElementById('newSupplierModal').style.display = 'none';
        }

        function selectSupplierType(type) {
            document.getElementById('newSupplierModal').dataset.supplierType = type;
            const vendorTab = document.getElementById('supplierTabVendor');
            const karigarTab = document.getElementById('supplierTabKarigar');
            vendorTab.classList.toggle('is-active', type === 'vendor');
            karigarTab.classList.toggle('is-active', type === 'karigar');
            if (type === 'vendor') {
                document.getElementById('newSupplierInput').placeholder = 'e.g. Mehta Jewellers, Rajesh Traders';
            } else {
                document.getElementById('newSupplierInput').placeholder = 'e.g. Ramesh Kumar, Krishna Ornaments';
            }
        }

        document.getElementById('supplierTabVendor')?.addEventListener('click', () => selectSupplierType('vendor'));
        document.getElementById('supplierTabKarigar')?.addEventListener('click', () => selectSupplierType('karigar'));
        document.getElementById('newSupplierBackdrop')?.addEventListener('click', () => closeNewSupplierModal());
        document.getElementById('newSupplierCancel')?.addEventListener('click', () => closeNewSupplierModal());

        document.getElementById('newSupplierInput')?.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); document.getElementById('newSupplierConfirm').click(); }
            if (e.key === 'Escape') closeNewSupplierModal();
        });

        document.getElementById('newSupplierConfirm')?.addEventListener('click', function () {
            const type = document.getElementById('newSupplierModal').dataset.supplierType || 'vendor';
            const input = document.getElementById('newSupplierInput');
            const errorEl = document.getElementById('newSupplierError');
            const name = input.value.trim();

            errorEl.classList.add('hidden');
            errorEl.textContent = '';

            if (!name) {
                errorEl.textContent = 'Please enter a name.';
                errorEl.classList.remove('hidden');
                input.focus();
                return;
            }

            const btn = this;
            if (!quickAdd.begin(btn.closest('[id$="Modal"]').id)) return;
            btn.disabled = true;
            btn.textContent = 'Adding…';

            const url = type === 'vendor'
                ? '{{ route('vendors.store') }}'
                : '{{ route('inventory.items.quick-add-karigar') }}';

            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ name }),
            })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    errorEl.textContent = data.errors?.name?.[0] || data.error || 'Failed to add. Please try again.';
                    errorEl.classList.remove('hidden');
                    quickAdd.end(btn.closest('[id$="Modal"]').id);
                    btn.disabled = false;
                    btn.textContent = 'Add & Use';
                    return;
                }

                const picker = document.getElementById('supplier_picker');
                const optValue = type + ':' + data.id;
                const opt = document.createElement('option');
                opt.value = optValue;
                opt.textContent = data.name;

                // Find or create the right optgroup
                let group = Array.from(picker.querySelectorAll('optgroup')).find(g =>
                    g.label.toLowerCase() === (type === 'vendor' ? 'vendors' : 'karigars')
                );
                if (!group) {
                    group = document.createElement('optgroup');
                    group.label = type === 'vendor' ? 'Vendors' : 'Karigars';
                    const sentinel = picker.querySelector('option[value="__new_supplier__"]');
                    picker.insertBefore(group, sentinel);
                }
                const sentinel = picker.querySelector('option[value="__new_supplier__"]');
                group.insertBefore(opt, null);
                picker.value = optValue;
                noteChoice(picker);
                {{ $refreshDropdown }}(picker);
                syncSupplierHiddenFields(optValue);

                quickAdd.end('newSupplierModal');
                closeNewSupplierModal(true);
            })
            .catch(() => {
                errorEl.textContent = 'Network error. Please try again.';
                errorEl.classList.remove('hidden');
                quickAdd.end(btn.closest('[id$="Modal"]').id);
                btn.disabled = false;
                btn.textContent = 'Add & Use';
            });
        });

        // ── New category modal ─────────────────────────────────────────────────

        function openNewCategoryModal() {
            document.getElementById('newCategoryInput').value = '';
            document.getElementById('newCategoryError').classList.add('hidden');
            document.getElementById('newCategoryError').textContent = '';
            document.getElementById('newCategoryConfirm').disabled = false;
            document.getElementById('newCategoryConfirm').textContent = 'Add & Use This Category';
            document.getElementById('newCategoryModal').style.display = 'flex';
            document.getElementById('newCategoryInput').focus();
        }

        function closeNewCategoryModal(force = false) {
            if (force !== true && quickAdd.saving('newCategoryModal')) return;
            document.getElementById('newCategoryModal').style.display = 'none';
        }

        document.getElementById('newCategoryBackdrop')?.addEventListener('click', () => closeNewCategoryModal());
        document.getElementById('newCategoryCancel')?.addEventListener('click', () => closeNewCategoryModal());

        document.getElementById('newCategoryInput')?.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); document.getElementById('newCategoryConfirm').click(); }
            if (e.key === 'Escape') closeNewCategoryModal();
        });

        document.getElementById('newCategoryConfirm')?.addEventListener('click', function () {
            const input = document.getElementById('newCategoryInput');
            const errorEl = document.getElementById('newCategoryError');
            const name = input.value.trim();

            errorEl.classList.add('hidden');
            errorEl.textContent = '';

            if (!name) {
                errorEl.textContent = 'Please enter a category name.';
                errorEl.classList.remove('hidden');
                input.focus();
                return;
            }

            const btn = this;
            if (!quickAdd.begin(btn.closest('[id$="Modal"]').id)) return;
            btn.disabled = true;
            btn.textContent = 'Adding…';

            fetch('{{ route('inventory.items.quick-add-category') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ name }),
            })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    errorEl.textContent = data.errors?.name?.[0] || data.error || 'Failed to add category.';
                    errorEl.classList.remove('hidden');
                    quickAdd.end(btn.closest('[id$="Modal"]').id);
                    btn.disabled = false;
                    btn.textContent = 'Add & Use This Category';
                    return;
                }

                // Inject into JS data map
                categoriesData[String(data.id)] = [];

                // Append option to category select and select it
                const categorySelect = document.getElementById('category');
                const opt = document.createElement('option');
                opt.value = data.name;
                opt.textContent = data.name;
                opt.setAttribute('data-category-id', String(data.id));
                // Insert before the sentinel option
                const sentinel = Array.from(categorySelect.options).find(o => o.value === '__new_category__');
                categorySelect.insertBefore(opt, sentinel);
                categorySelect.value = data.name;
                noteChoice(categorySelect);
                {{ $refreshDropdown }}(categorySelect);

                quickAdd.end('newCategoryModal');
                closeNewCategoryModal(true);
                handleCategoryChange();
            })
            .catch(() => {
                errorEl.textContent = 'Network error. Please try again.';
                errorEl.classList.remove('hidden');
                quickAdd.end(btn.closest('[id$="Modal"]').id);
                btn.disabled = false;
                btn.textContent = 'Add & Use This Category';
            });
        });

        // ── New sub-category modal ──────────────────────────────────────────────

        function openNewSubCategoryModal() {
            const categorySelect = document.getElementById('category');
            const selectedOption = categorySelect.options[categorySelect.selectedIndex];
            const categoryName = selectedOption?.textContent?.trim() || '';
            document.getElementById('newSubCategoryParentDisplay').value = categoryName || '(none selected)';
            document.getElementById('newSubCategoryInput').value = '';
            document.getElementById('newSubCategoryError').classList.add('hidden');
            document.getElementById('newSubCategoryError').textContent = '';
            document.getElementById('newSubCategoryConfirm').disabled = false;
            document.getElementById('newSubCategoryConfirm').textContent = 'Add & Use This Sub-Category';
            document.getElementById('newSubCategoryModal').style.display = 'flex';
            document.getElementById('newSubCategoryInput').focus();
        }

        function closeNewSubCategoryModal(force = false) {
            if (force !== true && quickAdd.saving('newSubCategoryModal')) return;
            document.getElementById('newSubCategoryModal').style.display = 'none';
        }

        document.getElementById('newSubCategoryBackdrop')?.addEventListener('click', () => closeNewSubCategoryModal());
        document.getElementById('newSubCategoryCancel')?.addEventListener('click', () => closeNewSubCategoryModal());

        document.getElementById('newSubCategoryInput')?.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); document.getElementById('newSubCategoryConfirm').click(); }
            if (e.key === 'Escape') closeNewSubCategoryModal();
        });

        document.getElementById('newSubCategoryConfirm')?.addEventListener('click', function () {
            const categorySelect = document.getElementById('category');
            const selectedOption = categorySelect.options[categorySelect.selectedIndex];
            const categoryId = selectedOption?.getAttribute('data-category-id');
            const input = document.getElementById('newSubCategoryInput');
            const errorEl = document.getElementById('newSubCategoryError');
            const name = input.value.trim();

            errorEl.classList.add('hidden');
            errorEl.textContent = '';

            if (!categoryId) {
                errorEl.textContent = 'Please select a category first.';
                errorEl.classList.remove('hidden');
                return;
            }
            if (!name) {
                errorEl.textContent = 'Please enter a sub-category name.';
                errorEl.classList.remove('hidden');
                input.focus();
                return;
            }

            const btn = this;
            if (!quickAdd.begin(btn.closest('[id$="Modal"]').id)) return;
            btn.disabled = true;
            btn.textContent = 'Adding…';

            fetch('{{ route('inventory.items.quick-add-sub-category') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ category_id: Number(categoryId), name }),
            })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    errorEl.textContent = data.errors?.name?.[0] || data.error || 'Failed to add sub-category.';
                    errorEl.classList.remove('hidden');
                    quickAdd.end(btn.closest('[id$="Modal"]').id);
                    btn.disabled = false;
                    btn.textContent = 'Add & Use This Sub-Category';
                    return;
                }

                // Inject into JS data map
                if (!categoriesData[categoryId]) categoriesData[categoryId] = [];
                if (!categoriesData[categoryId].includes(data.name)) {
                    categoriesData[categoryId].push(data.name);
                }

                // Rebuild sub-category dropdown and select the new entry
                const subSelect = document.getElementById('sub_category');
                subSelect.dataset.initialValue = data.name;
                handleCategoryChange();
                subSelect.value = data.name;
                noteChoice(subSelect);
                {{ $refreshDropdown }}(subSelect);

                quickAdd.end('newSubCategoryModal');
                closeNewSubCategoryModal(true);
            })
            .catch(() => {
                errorEl.textContent = 'Network error. Please try again.';
                errorEl.classList.remove('hidden');
                quickAdd.end(btn.closest('[id$="Modal"]').id);
                btn.disabled = false;
                btn.textContent = 'Add & Use This Sub-Category';
            });
        });

        // ── Custom purity modal ────────────────────────────────────────────────

        function openCustomPurityModal() {
            const metalType = document.getElementById('metal_type').value;
            const isGold = metalType === 'gold';

            document.getElementById('customPurityMetalDisplay').value =
                metalType ? metalType.charAt(0).toUpperCase() + metalType.slice(1) : '';
            document.getElementById('customPurityHint').textContent =
                isGold ? '(0.001 – 24)' : '(0.001 – 1000)';
            document.getElementById('customPuritySubhint').textContent =
                isGold
                    ? 'Enter karat value, e.g. 20 for 20K or 916 for BIS hallmark.'
                    : 'Enter millesimal value, e.g. 958 for Britannia silver.';
            document.getElementById('customPurityInput').value = '';
            document.getElementById('customPurityInput').max = isGold ? 24 : 1000;
            document.getElementById('customPurityError').classList.add('hidden');
            document.getElementById('customPurityError').textContent = '';
            document.getElementById('customPurityModal').style.display = 'flex';
            document.getElementById('customPurityInput').focus();
        }

        function closeCustomPurityModal(force = false) {
            if (force !== true && quickAdd.saving('customPurityModal')) return;
            document.getElementById('customPurityModal').style.display = 'none';
        }

        document.getElementById('customPurityBackdrop')?.addEventListener('click', () => closeCustomPurityModal());
        document.getElementById('customPurityCancel')?.addEventListener('click', () => closeCustomPurityModal());

        document.getElementById('customPurityInput')?.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('customPurityConfirm').click();
            }
            if (e.key === 'Escape') closeCustomPurityModal();
        });

        document.getElementById('customPurityConfirm')?.addEventListener('click', function () {
            const metalType = document.getElementById('metal_type').value;
            const purityInput = document.getElementById('customPurityInput');
            const errorEl = document.getElementById('customPurityError');
            const purityValue = Number.parseFloat(purityInput.value);

            errorEl.classList.add('hidden');
            errorEl.textContent = '';

            if (!Number.isFinite(purityValue) || purityValue <= 0) {
                errorEl.textContent = 'Please enter a valid purity value greater than zero.';
                errorEl.classList.remove('hidden');
                purityInput.focus();
                return;
            }
            if (metalType === 'gold' && purityValue > 24) {
                errorEl.textContent = 'Gold purity cannot exceed 24K.';
                errorEl.classList.remove('hidden');
                purityInput.focus();
                return;
            }
            if (metalType === 'silver' && purityValue > 1000) {
                errorEl.textContent = 'Silver purity cannot exceed 1000.';
                errorEl.classList.remove('hidden');
                purityInput.focus();
                return;
            }

            const btn = document.getElementById('customPurityConfirm');
            if (!quickAdd.begin(btn.closest('[id$="Modal"]').id)) return;
            btn.disabled = true;
            btn.textContent = 'Adding…';

            fetch('{{ route('inventory.items.quick-add-purity') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ metal_type: metalType, purity_value: purityValue }),
            })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    errorEl.textContent = data.error || 'Failed to add purity. Please try again.';
                    errorEl.classList.remove('hidden');
                    quickAdd.end(btn.closest('[id$="Modal"]').id);
                    btn.disabled = false;
                    btn.textContent = 'Add & Use This Purity';
                    return;
                }

                // Inject into the local profiles map so future metal-type switches keep it
                if (!purityProfiles[metalType]) purityProfiles[metalType] = [];
                const alreadyExists = purityProfiles[metalType].some(p => p.value === data.value);
                if (!alreadyExists) {
                    purityProfiles[metalType].push({ value: data.value, label: data.label });
                }

                // Inject into the resolved rates map
                if (!resolvedRates[metalType]) resolvedRates[metalType] = {};
                resolvedRates[metalType][data.value] = {
                    label: data.label,
                    rate_per_gram: data.rate_per_gram,
                };

                // Rebuild the dropdown and select the new purity
                const puritySelect = document.getElementById('purity');
                puritySelect.dataset.initialValue = data.value;
                populatePurityOptions();
                puritySelect.value = data.value;
                noteChoice(puritySelect);
                {{ $refreshDropdown }}(puritySelect);

                quickAdd.end('customPurityModal');
                closeCustomPurityModal(true);
                refreshRetailerPricing();
            })
            .catch(() => {
                errorEl.textContent = 'Network error. Please check your connection and try again.';
                errorEl.classList.remove('hidden');
                quickAdd.end(btn.closest('[id$="Modal"]').id);
                btn.disabled = false;
                btn.textContent = 'Add & Use This Purity';
            });
        });
