(() => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const state = document.getElementById('save-state');
    const dialog = document.getElementById('day-dialog');
    if (!dialog) return;

    const dialogForm = document.getElementById('day-dialog-form');
    const errorBox = document.getElementById('dialog-error');
    let activeRow = null;
    let timer = null;

    const normalizeTime = value => {
        const v = value.trim().replace('.', ':');
        if (/^\d{1,3}:\d{1,2}$/.test(v)) {
            const [h, m] = v.split(':');
            return `${h.padStart(2, '0')}:${m.padStart(2, '0')}`;
        }
        return v;
    };

    async function saveRow(row, overrides = {}) {
        const date = row.dataset.date;
        const body = {
            driving: normalizeTime(row.querySelector('[name="driving"]')?.value || ''),
            warehouse: normalizeTime(row.querySelector('[name="warehouse"]')?.value || ''),
            end_time: normalizeTime(row.querySelector('[name="end_time"]')?.value || ''),
            note: row.querySelector('[name="note"]')?.value || '',
            meal_mode: row.dataset.mealMode || 'auto',
            meal_amount: row.dataset.mealAmount || '',
            ...overrides,
        };
        if (state) { state.textContent = 'Enregistrement…'; state.style.color = '#6d7890'; }
        const response = await fetch(`/jours/${date}`, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token},
            body: JSON.stringify(body),
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            const first = data.errors ? Object.values(data.errors).flat()[0] : 'Erreur lors de l’enregistrement.';
            if (state) { state.textContent = first; state.style.color = '#e84b55'; }
            throw new Error(first);
        }
        if (state) { state.textContent = 'Enregistré ✓'; state.style.color = '#198754'; }
        clearTimeout(timer);
        timer = setTimeout(() => location.reload(), 1500);
    }

    document.querySelectorAll('.autosave').forEach(input => {
        input.addEventListener('input', () => clearTimeout(timer));
        input.addEventListener('change', async () => {
            try { await saveRow(input.closest('.work-row')); } catch (_) {}
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') { event.preventDefault(); input.blur(); }
        });
    });

    document.querySelectorAll('.edit-day').forEach(button => button.addEventListener('click', () => {
        activeRow = button.closest('.work-row');
        const date = activeRow.dataset.date;
        document.getElementById('dialog-title').textContent = date.split('-').reverse().join('/');
        dialogForm.elements.driving.value = activeRow.querySelector('[name="driving"]')?.value || '';
        dialogForm.elements.warehouse.value = activeRow.querySelector('[name="warehouse"]')?.value || '';
        dialogForm.elements.end_time.value = activeRow.querySelector('[name="end_time"]')?.value || '';
        dialogForm.elements.note.value = activeRow.querySelector('[name="note"]')?.value || '';
        dialogForm.elements.meal_mode.value = activeRow.dataset.mealMode || 'auto';
        dialogForm.elements.meal_amount.value = activeRow.dataset.mealAmount || '';
        errorBox.textContent = '';
        dialog.showModal();
    }));

    document.getElementById('save-day').addEventListener('click', async () => {
        if (!activeRow) return;
        errorBox.textContent = '';
        const overrides = {
            driving: normalizeTime(dialogForm.elements.driving.value),
            warehouse: normalizeTime(dialogForm.elements.warehouse.value),
            end_time: normalizeTime(dialogForm.elements.end_time.value),
            note: dialogForm.elements.note.value,
            meal_mode: dialogForm.elements.meal_mode.value,
            meal_amount: dialogForm.elements.meal_amount.value,
        };
        try {
            await saveRow(activeRow, overrides);
            dialog.close();
        } catch (error) { errorBox.textContent = error.message; }
    });

    document.getElementById('delete-day').addEventListener('click', async () => {
        if (!activeRow || !confirm('Effacer toutes les données de cette journée ?')) return;
        const response = await fetch(`/jours/${activeRow.dataset.date}`, {method:'DELETE', headers:{'Accept':'application/json','X-CSRF-TOKEN':token}});
        if (response.ok) location.reload();
    });
})();
