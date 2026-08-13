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

    const parseClock = value => {
        const match = normalizeTime(value).match(/^(\d{1,2}):([0-5]\d)$/);
        if (!match || Number(match[1]) > 23) return null;
        return Number(match[1]) * 60 + Number(match[2]);
    };

    const parseDuration = value => {
        const normalized = normalizeTime(value);
        if (normalized === '') return 0;
        const match = normalized.match(/^(\d{1,3}):([0-5]\d)$/);
        return match ? Number(match[1]) * 60 + Number(match[2]) : null;
    };

    const formatDuration = minutes => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
    const formatClock = minutes => formatDuration(((minutes % 1440) + 1440) % 1440);
    const formatMoney = cents => {
        const whole = Math.floor(cents / 100);
        const decimal = cents % 100;
        if (decimal === 0) return `${whole} €`;
        return `${whole},${String(decimal).padStart(2, '0').replace(/0$/, '')} €`;
    };
    const parseMoney = value => {
        const normalized = String(value || '').trim().replace(/\s|€/g, '').replace(',', '.');
        if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) return 0;
        return Math.round(Number(normalized) * 100);
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

    const rowValues = row => {
        const start = parseClock(q('[name="start_time"]', row)?.value || '');
        const driving = parseDuration(q('[name="driving"]', row)?.value || '');
        const warehouse = parseDuration(q('[name="warehouse"]', row)?.value || '');
        if (start === null || driving === null || warehouse === null) return null;
        return {worked: driving + warehouse, end: start + driving + warehouse};
    };

    const syncRow = row => {
        const isRest = q('.rest-toggle', row)?.checked ?? row.dataset.isRest === '1';
        row.dataset.isRest = isRest ? '1' : '0';
        qa('[name="start_time"], [name="driving"], [name="warehouse"]', row).forEach(input => { input.disabled = isRest; });
        qa('.edit-day', row).forEach(button => { button.disabled = isRest; });

        const stateLabel = q('.row-state-label', row);
        row.classList.toggle('row-rest', isRest);
        if (isRest) {
            row.classList.remove('row-needs-fill', 'row-filled');
            if (stateLabel) stateLabel.textContent = 'Repos';
            q('.total-cell strong', row).textContent = '—';
            q('.end-value', row).textContent = '—';
            q('.meal-button', row).textContent = '—';
            return;
        }

        const values = rowValues(row);
        if (!values) return;
        const needsFill = values.worked === 0;
        row.classList.toggle('row-needs-fill', needsFill);
        row.classList.toggle('row-filled', !needsFill);
        if (stateLabel) stateLabel.textContent = needsFill ? 'À remplir' : '';
        q('.total-cell strong', row).textContent = formatDuration(values.worked);
        q('.end-value', row).textContent = formatClock(values.end);

        const forced = (row.dataset.mealMode || 'auto') === 'forced';
        const mealCents = forced
            ? parseMoney(row.dataset.mealAmount)
            : values.end >= Number(row.dataset.mealThreshold || 0) ? Number(row.dataset.mealDefault || 0) : 0;
        q('.meal-button', row).textContent = `${formatMoney(mealCents)}⌄`;
    };

    async function saveRow(row, overrides = {}) {
        const body = {
            start_time: normalizeTime(q('[name="start_time"]', row)?.value || '07:45'),
            driving: normalizeTime(q('[name="driving"]', row)?.value || ''),
            warehouse: normalizeTime(q('[name="warehouse"]', row)?.value || ''),
            is_rest: q('.rest-toggle', row)?.checked ?? false,
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
        if (body.is_rest) {
            q('[name="start_time"]', row).value = '07:45';
            q('[name="driving"]', row).value = '';
            q('[name="warehouse"]', row).value = '';
            row.dataset.mealMode = 'auto';
            row.dataset.mealAmount = '';
        } else {
            q('[name="start_time"]', row).value = body.start_time;
            q('[name="driving"]', row).value = body.driving;
            q('[name="warehouse"]', row).value = body.warehouse;
            row.dataset.mealMode = body.meal_mode;
            row.dataset.mealAmount = body.meal_amount;
        }
        syncRow(row);
        setState('Enregistré ✓', '#198754');
        scheduleReload();
    }

    qa('.work-row').forEach(syncRow);
    qa('.rest-toggle').forEach(toggle => toggle.addEventListener('change', async () => {
        const row = toggle.closest('.work-row');
        const previous = !toggle.checked;
        syncRow(row);
        try { await saveRow(row); } catch (_) {
            toggle.checked = previous;
            syncRow(row);
        }
    }));
    qa('.autosave').forEach(input => {
        input.addEventListener('input', () => {
            clearTimeout(reloadTimer);
            syncRow(input.closest('.work-row'));
        });
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
    const syncDialogComputed = () => {
        const start = parseClock(form.elements.start_time.value);
        const driving = parseDuration(form.elements.driving.value);
        const warehouse = parseDuration(form.elements.warehouse.value);
        if (start === null || driving === null || warehouse === null) return;
        const end = start + driving + warehouse;
        q('#dialog-end').textContent = formatClock(end);
        if (activeRow) {
            const cents = end >= Number(activeRow.dataset.mealThreshold || 0) ? Number(activeRow.dataset.mealDefault || 0) : 0;
            q('#dialog-auto-amount').textContent = `(${formatMoney(cents)})`;
        }
    };

    qa('input[name="meal_mode"]', form).forEach(radio => radio.addEventListener('change', syncMealInput));
    ['start_time', 'driving', 'warehouse'].forEach(name => form.elements[name].addEventListener('input', syncDialogComputed));

    function openDialog(row) {
        activeRow = row;
        const date = row.dataset.date;
        q('#dialog-title').textContent = date.split('-').reverse().join('/');
        const dayName = q('.day-name', row)?.textContent.trim() || '';
        const dayLabel = q('#dialog-day-name');
        if (dayLabel) dayLabel.textContent = dayName ? `(${dayName})` : '';
        form.elements.start_time.value = q('[name="start_time"]', row)?.value || '07:45';
        form.elements.driving.value = q('[name="driving"]', row)?.value || '';
        form.elements.warehouse.value = q('[name="warehouse"]', row)?.value || '';
        form.elements.meal_mode.value = row.dataset.mealMode || 'auto';
        form.elements.meal_amount.value = row.dataset.mealAmount || '';
        errorBox.textContent = '';
        syncMealInput();
        syncDialogComputed();
        dialog.showModal();
    }

    qa('.edit-day').forEach(button => button.addEventListener('click', () => openDialog(button.closest('.work-row'))));
    q('#save-day')?.addEventListener('click', async () => {
        if (!activeRow) return;
        errorBox.textContent = '';
        const overrides = {
            start_time: normalizeTime(form.elements.start_time.value),
            driving: normalizeTime(form.elements.driving.value),
            warehouse: normalizeTime(form.elements.warehouse.value),
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
