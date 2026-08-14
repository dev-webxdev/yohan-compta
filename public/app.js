(() => {
    const q = (selector, root = document) => root.querySelector(selector);
    const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
    const shell = q('#app-shell');

    const normalizeTime = value => {
        const normalized = value.trim().replace('.', ':');
        if (/^\d{1,3}$/.test(normalized)) return `${normalized}:00`;
        if (!/^\d{1,3}:\d{1,2}$/.test(normalized)) return normalized;
        const [hours, minutes] = normalized.split(':');
        return `${hours}:${minutes.padStart(2, '0')}`;
    };

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
        const closeMobileMenu = () => {
            shell.classList.remove('mobile-menu-open');
            mobileToggle?.setAttribute('aria-expanded', 'false');
        };
        mobileToggle?.addEventListener('click', () => {
            const open = shell.classList.toggle('mobile-menu-open');
            mobileToggle.setAttribute('aria-expanded', String(open));
        });
        qa('.sidebar a').forEach(link => link.addEventListener('click', closeMobileMenu));
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') closeMobileMenu();
        });
    }

    const table = q('.work-table');
    const moreDays = q('#toggle-days-mobile');
    moreDays?.addEventListener('click', () => {
        const expanded = table?.classList.toggle('days-expanded') ?? false;
        moreDays.innerHTML = expanded ? 'Voir moins de jours <i class="fa-solid fa-chevron-up"></i>' : 'Voir plus de jours <i class="fa-solid fa-chevron-down"></i>';
    });

    qa('[data-time-normalize]').forEach(input => input.addEventListener('change', () => {
        input.value = normalizeTime(input.value);
    }));

    const confirmationDialog = q('#confirm-dialog');
    const confirmationTitle = q('#confirm-dialog-title');
    const confirmationMessage = q('#confirm-dialog-message');
    const confirmationSubmit = q('#confirm-dialog-submit');
    let confirmationResolver = null;

    const resolveConfirmation = result => {
        if (!confirmationResolver) return;
        const resolver = confirmationResolver;
        confirmationResolver = null;
        confirmationDialog?.close();
        resolver(result);
    };

    const askConfirmation = ({title, message, action = 'Confirmer', danger = false}) => new Promise(resolve => {
        if (!confirmationDialog || !confirmationTitle || !confirmationMessage || !confirmationSubmit) {
            resolve(false);
            return;
        }
        if (confirmationResolver) resolveConfirmation(false);
        confirmationResolver = resolve;
        confirmationTitle.textContent = title;
        confirmationMessage.textContent = message;
        confirmationSubmit.textContent = action;
        confirmationDialog.classList.toggle('is-danger', danger);
        confirmationDialog.showModal();
        q('#confirm-dialog-cancel')?.focus();
    });

    q('#confirm-dialog-cancel')?.addEventListener('click', () => resolveConfirmation(false));
    confirmationSubmit?.addEventListener('click', () => resolveConfirmation(true));
    confirmationDialog?.addEventListener('cancel', event => {
        event.preventDefault();
        resolveConfirmation(false);
    });

    const confirmedForms = new WeakSet();
    qa('form[data-confirm]').forEach(confirmForm => confirmForm.addEventListener('submit', async event => {
        if (confirmedForms.has(confirmForm)) {
            confirmedForms.delete(confirmForm);
            return;
        }
        event.preventDefault();
        const accepted = await askConfirmation({
            title: confirmForm.dataset.confirmTitle || 'Confirmer l’action ?',
            message: confirmForm.dataset.confirmMessage || 'Voulez-vous continuer ?',
            action: confirmForm.dataset.confirmAction || 'Confirmer',
            danger: confirmForm.dataset.confirmDanger === '1',
        });
        if (!accepted) return;
        confirmedForms.add(confirmForm);
        if (event.submitter) confirmForm.requestSubmit(event.submitter);
        else confirmForm.requestSubmit();
    }));

    const dialog = q('#day-dialog');
    if (!dialog) return;

    const token = q('meta[name="csrf-token"]')?.content;
    const state = q('#save-state');
    const saveToast = q('#save-toast');
    const form = q('#day-dialog-form');
    const errorBox = q('#dialog-error');
    const mealAmount = form.elements.meal_amount;
    let activeRow = null;
    let summaryRefreshController = null;
    const autosaveTimers = new WeakMap();
    const saveQueues = new WeakMap();
    const saveVersions = new WeakMap();
    const savedRowStates = new WeakMap();
    let saveToastTimer = null;
    const clearAutosaveTimer = row => {
        const timer = autosaveTimers.get(row);
        if (timer) clearTimeout(timer);
        autosaveTimers.delete(row);
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

    const setRowSaveState = (row, status, message = '') => {
        const indicator = q('.row-save-state', row);
        if (!indicator) return;
        indicator.dataset.state = status;
        indicator.textContent = status === 'saving' ? '…' : status === 'saved' ? '✓' : status === 'error' ? '!' : '';
        indicator.title = message;
        indicator.setAttribute('aria-label', message || 'Aucune modification en attente');
    };

    const showSaveError = message => {
        if (!saveToast) return;
        saveToast.textContent = message;
        saveToast.hidden = false;
        if (saveToastTimer) clearTimeout(saveToastTimer);
        saveToastTimer = setTimeout(() => {
            saveToast.hidden = true;
            saveToastTimer = null;
        }, 5000);
    };

    const refreshDashboardSummary = async () => {
        summaryRefreshController?.abort();
        summaryRefreshController = new AbortController();
        try {
            const response = await fetch(location.href, {
                headers: {Accept: 'text/html'},
                signal: summaryRefreshController.signal,
            });
            if (!response.ok) return;
            const freshDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
            ['.dashboard-kpis', '#weeks', '#balance', '.below-fold-summary'].forEach(selector => {
                const current = q(selector);
                const fresh = q(selector, freshDocument);
                if (current && fresh) current.innerHTML = fresh.innerHTML;
            });
        } catch (error) {
            if (error.name !== 'AbortError') console.error('Dashboard refresh failed', error);
        }
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
        qa('.edit-day, .edit-meal', row).forEach(button => { button.disabled = isRest; });

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
        q('.meal-button', row).innerHTML = `${formatMoney(mealCents)} <i class="fa-solid fa-chevron-down"></i>`;
    };

    const rowBody = row => ({
        start_time: normalizeTime(q('[name="start_time"]', row)?.value || '07:45'),
        driving: normalizeTime(q('[name="driving"]', row)?.value || ''),
        warehouse: normalizeTime(q('[name="warehouse"]', row)?.value || ''),
        is_rest: q('.rest-toggle', row)?.checked ?? false,
        meal_mode: row.dataset.mealMode || 'auto',
        meal_amount: row.dataset.mealAmount || '',
    });

    function saveRow(row, overrides = {}) {
        const body = {
            ...rowBody(row),
            ...overrides,
        };
        const stateSignature = JSON.stringify(body);
        const pending = saveQueues.get(row);
        if (!pending && Object.keys(overrides).length === 0 && savedRowStates.get(row) === stateSignature) {
            return Promise.resolve();
        }

        const version = (saveVersions.get(row) || 0) + 1;
        saveVersions.set(row, version);
        const task = (pending || Promise.resolve())
            .catch(() => {})
            .then(async () => {
                setState('Enregistrement…', '#6d7890');
                setRowSaveState(row, 'saving', 'Enregistrement en cours');
                const response = await fetch(`/jours/${row.dataset.date}`, {
                    method: 'PUT',
                    headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token},
                    body: JSON.stringify(body),
                    keepalive: true,
                });
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    const message = data.errors ? Object.values(data.errors).flat()[0] : 'Erreur lors de l’enregistrement.';
                    if (saveVersions.get(row) === version) {
                        setState(message, '#e84b55');
                        setRowSaveState(row, 'error', message);
                        showSaveError(message);
                    }
                    throw new Error(message);
                }
                if (saveVersions.get(row) !== version) return;
                if (!body.is_rest) {
                    q('[name="start_time"]', row).value = body.start_time;
                    q('[name="driving"]', row).value = body.driving;
                    q('[name="warehouse"]', row).value = body.warehouse;
                    row.dataset.mealMode = body.meal_mode;
                    row.dataset.mealAmount = body.meal_amount;
                }
                syncRow(row);
                savedRowStates.set(row, JSON.stringify(rowBody(row)));
                setState('Enregistré ✓', '#198754');
                setRowSaveState(row, 'saved', 'Enregistré');
                void refreshDashboardSummary();
            });
        saveQueues.set(row, task);

        return task.finally(() => {
            if (saveQueues.get(row) === task) saveQueues.delete(row);
        });
    }

    qa('.work-row').forEach(row => {
        syncRow(row);
        savedRowStates.set(row, JSON.stringify(rowBody(row)));
    });

    const flushPendingRows = () => {
        qa('.work-row').forEach(row => {
            const body = rowBody(row);
            if (savedRowStates.get(row) === JSON.stringify(body)) return;
            if (!body.is_rest && !rowValues(row)) return;
            clearAutosaveTimer(row);
            fetch(`/jours/${row.dataset.date}`, {
                method: 'PUT',
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token},
                body: JSON.stringify(body),
                keepalive: true,
            }).catch(() => {});
        });
    };

    window.addEventListener('pagehide', flushPendingRows);

    qa('.rest-toggle').forEach(toggle => toggle.addEventListener('change', async () => {
        const row = toggle.closest('.work-row');
        const previous = !toggle.checked;
        clearAutosaveTimer(row);
        syncRow(row);
        try { await saveRow(row); } catch (_) {
            toggle.checked = previous;
            syncRow(row);
        }
    }));
    qa('.autosave').forEach(input => {
        input.addEventListener('input', () => {
            const row = input.closest('.work-row');
            clearAutosaveTimer(row);
            syncRow(row);
            if (!rowValues(row)) return;
            autosaveTimers.set(row, setTimeout(async () => {
                autosaveTimers.delete(row);
                try { await saveRow(row); } catch (_) {}
            }, 450));
        });
        input.addEventListener('change', async () => {
            const row = input.closest('.work-row');
            clearAutosaveTimer(row);
            try { await saveRow(row); } catch (_) {}
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

    function openDialog(row, mode = 'day') {
        clearAutosaveTimer(row);
        activeRow = row;
        dialog.dataset.mode = mode;
        dialog.classList.toggle('meal-only', mode === 'meal');
        const date = row.dataset.date;
        q('#dialog-heading-prefix').textContent = mode === 'meal' ? 'Panier du' : 'Édition du';
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

    qa('.edit-meal').forEach(button => button.addEventListener('click', () => openDialog(button.closest('.work-row'), 'meal')));
    qa('.edit-day').forEach(button => button.addEventListener('click', () => openDialog(button.closest('.work-row'), 'day')));
    q('#save-day')?.addEventListener('click', async () => {
        if (!activeRow) return;
        errorBox.textContent = '';
        const overrides = {
            meal_mode: form.elements.meal_mode.value,
            meal_amount: form.elements.meal_amount.value,
        };
        if (dialog.dataset.mode !== 'meal') {
            Object.assign(overrides, {
                start_time: normalizeTime(form.elements.start_time.value),
                driving: normalizeTime(form.elements.driving.value),
                warehouse: normalizeTime(form.elements.warehouse.value),
            });
        }
        try {
            await saveRow(activeRow, overrides);
            dialog.close();
        } catch (error) {
            errorBox.textContent = error.message;
        }
    });

    q('#delete-day')?.addEventListener('click', async () => {
        if (!activeRow) return;
        const accepted = await askConfirmation({
            title: 'Supprimer cette journée ?',
            message: 'Toutes les données enregistrées pour cette journée seront effacées.',
            action: 'Supprimer',
            danger: true,
        });
        if (!accepted) return;
        clearAutosaveTimer(activeRow);
        await (saveQueues.get(activeRow) || Promise.resolve()).catch(() => {});
        const response = await fetch(`/jours/${activeRow.dataset.date}`, {
            method: 'DELETE',
            headers: {Accept: 'application/json', 'X-CSRF-TOKEN': token},
        });
        if (response.ok) location.reload();
    });
})();
