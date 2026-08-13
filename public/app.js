(() => {
    const q = (selector, root = document) => root.querySelector(selector);
    const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
    const shell = q('#app-shell');
    const storageKey = 'yohan-compta-sidebar-collapsed';

    if (shell) {
        if (localStorage.getItem(storageKey) === '1') shell.classList.add('sidebar-collapsed');

        const syncSidebarButtons = () => {
            const expanded = !shell.classList.contains('sidebar-collapsed');
            qa('.sidebar-toggle').forEach(button => button.setAttribute('aria-expanded', String(expanded)));
        };
        syncSidebarButtons();

        qa('.sidebar-toggle').forEach(button => button.addEventListener('click', () => {
            shell.classList.toggle('sidebar-collapsed');
            localStorage.setItem(storageKey, shell.classList.contains('sidebar-collapsed') ? '1' : '0');
            syncSidebarButtons();
        }));

        const mobileToggle = q('.mobile-menu-toggle');
        mobileToggle?.addEventListener('click', () => {
            const open = shell.classList.toggle('mobile-menu-open');
            mobileToggle.setAttribute('aria-expanded', String(open));
        });
        qa('.sidebar a').forEach(link => link.addEventListener('click', () => shell.classList.remove('mobile-menu-open')));
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') shell.classList.remove('mobile-menu-open');
        });
    }

    const table = q('.work-table');
    const moreDays = q('#toggle-days-mobile');
    moreDays?.addEventListener('click', () => {
        const expanded = table?.classList.toggle('days-expanded') ?? false;
        moreDays.textContent = expanded ? 'Voir moins de jours⌃' : 'Voir plus de jours⌄';
    });

    const dialog = q('#day-dialog');
    if (!dialog) return;

    const token = q('meta[name="csrf-token"]')?.content;
    const state = q('#save-state');
    const form = q('#day-dialog-form');
    const errorBox = q('#dialog-error');
    const mealAmount = form.elements.meal_amount;
    let activeRow = null;
    let reloadTimer = null;

    const normalizeTime = value => {
        const normalized = value.trim().replace('.', ':');
        if (!/^\d{1,3}:\d{1,2}$/.test(normalized)) return normalized;
        const [hours, minutes] = normalized.split(':');
        return `${hours.padStart(2, '0')}:${minutes.padStart(2, '0')}`;
    };

    const setState = (text, color) => {
        if (!state) return;
        state.textContent = text;
        state.style.color = color;
    };

    const scheduleReload = () => {
        clearTimeout(reloadTimer);
        const refreshWhenIdle = () => {
            const focusedEditor = document.activeElement?.closest?.('.work-row, #day-dialog');
            if (focusedEditor || dialog.open) {
                reloadTimer = setTimeout(refreshWhenIdle, 700);
                return;
            }
            location.reload();
        };
        reloadTimer = setTimeout(refreshWhenIdle, 1400);
    };

    async function saveRow(row, overrides = {}) {
        const body = {
            driving: normalizeTime(q('[name="driving"]', row)?.value || ''),
            warehouse: normalizeTime(q('[name="warehouse"]', row)?.value || ''),
            end_time: normalizeTime(q('[name="end_time"]', row)?.value || ''),
            note: q('[name="note"]', row)?.value || '',
            meal_mode: row.dataset.mealMode || 'auto',
            meal_amount: row.dataset.mealAmount || '',
            ...overrides,
        };
        setState('Enregistrement…', '#6d7890');
        const response = await fetch(`/jours/${row.dataset.date}`, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token},
            body: JSON.stringify(body),
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            const message = data.errors ? Object.values(data.errors).flat()[0] : 'Erreur lors de l’enregistrement.';
            setState(message, '#e84b55');
            throw new Error(message);
        }
        row.dataset.mealMode = body.meal_mode;
        row.dataset.mealAmount = body.meal_amount;
        setState('Enregistré ✓', '#198754');
        scheduleReload();
    }

    qa('.autosave').forEach(input => {
        input.addEventListener('input', () => clearTimeout(reloadTimer));
        input.addEventListener('change', async () => {
            try { await saveRow(input.closest('.work-row')); } catch (_) {}
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') { event.preventDefault(); input.blur(); }
        });
    });

    const syncMealInput = () => {
        const forced = form.elements.meal_mode.value === 'forced';
        mealAmount.disabled = !forced;
        if (!forced) mealAmount.value = '';
    };
    qa('input[name="meal_mode"]', form).forEach(radio => radio.addEventListener('change', syncMealInput));

    function openDialog(row) {
        activeRow = row;
        const date = row.dataset.date;
        q('#dialog-title').textContent = date.split('-').reverse().join('/');
        const dayName = q('.day-name', row)?.textContent.trim() || '';
        const dayLabel = q('#dialog-day-name');
        if (dayLabel) dayLabel.textContent = dayName ? `(${dayName})` : '';
        form.elements.driving.value = q('[name="driving"]', row)?.value || '';
        form.elements.warehouse.value = q('[name="warehouse"]', row)?.value || '';
        form.elements.end_time.value = q('[name="end_time"]', row)?.value || '';
        form.elements.note.value = q('[name="note"]', row)?.value || '';
        form.elements.meal_mode.value = row.dataset.mealMode || 'auto';
        form.elements.meal_amount.value = row.dataset.mealAmount || '';
        errorBox.textContent = '';
        syncMealInput();
        dialog.showModal();
    }

    qa('.edit-day').forEach(button => button.addEventListener('click', () => openDialog(button.closest('.work-row'))));
    q('#add-day')?.addEventListener('click', () => {
        const emptyRow = qa('.work-row').find(row =>
            !q('[name="driving"]', row)?.value &&
            !q('[name="warehouse"]', row)?.value &&
            !q('[name="end_time"]', row)?.value &&
            !q('[name="note"]', row)?.value &&
            (row.dataset.mealMode || 'auto') === 'auto'
        );
        openDialog(emptyRow || qa('.work-row')[0]);
    });

    q('#save-day')?.addEventListener('click', async () => {
        if (!activeRow) return;
        errorBox.textContent = '';
        const overrides = {
            driving: normalizeTime(form.elements.driving.value),
            warehouse: normalizeTime(form.elements.warehouse.value),
            end_time: normalizeTime(form.elements.end_time.value),
            note: form.elements.note.value,
            meal_mode: form.elements.meal_mode.value,
            meal_amount: form.elements.meal_amount.value,
        };
        try {
            await saveRow(activeRow, overrides);
            dialog.close();
        } catch (error) {
            errorBox.textContent = error.message;
        }
    });

    q('#delete-day')?.addEventListener('click', async () => {
        if (!activeRow || !confirm('Effacer toutes les données de cette journée ?')) return;
        const response = await fetch(`/jours/${activeRow.dataset.date}`, {
            method: 'DELETE',
            headers: {Accept: 'application/json', 'X-CSRF-TOKEN': token},
        });
        if (response.ok) location.reload();
    });
})();
