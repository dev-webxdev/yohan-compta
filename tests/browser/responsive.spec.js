const {test, expect} = require('@playwright/test');

async function login(page) {
    await page.goto('/connexion');
    await page.getByLabel('Adresse e-mail').fill('e2e@example.com');
    await page.getByLabel('Mot de passe').fill('e2e-password');
    await Promise.all([
        page.waitForURL(/\/mois(?:\/\d{4}-\d{2})?$/),
        page.getByRole('button', {name: 'Se connecter'}).click(),
    ]);
}

async function ensureEditable(row) {
    if (await row.getAttribute('data-locked') !== '1') return;
    if (await row.getAttribute('data-unlocked') === '1') return;

    await row.locator('.day-lock-toggle').click();
    await expect(row).toHaveAttribute('data-unlocked', '1');
}

test.beforeEach(async ({page}) => {
    await login(page);
});

test('dashboard stays usable without page-level horizontal overflow', async ({page}) => {
    await page.goto('/mois/2026-08');
    await expect(page.getByRole('heading', {name: 'Jours du mois'})).toBeVisible();
    await expect(page.getByText('Heures ce mois')).toBeVisible();

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);

    const firstRow = page.locator('.work-row').first();
    await expect(firstRow).toBeVisible();
    await expect(firstRow.locator('[name="driving"]')).toHaveAttribute('aria-label', /Conduite du/);

    const calendar = page.locator('.mobile-calendar');
    if (await calendar.isVisible()) {
        const calendarBox = await calendar.boundingBox();
        const menuBox = await page.locator('.mobile-menu-toggle').boundingBox();
        expect(calendarBox).not.toBeNull();
        expect(menuBox).not.toBeNull();
        expect(calendarBox.width).toBeGreaterThanOrEqual(40);
        expect(calendarBox.height).toBeGreaterThanOrEqual(40);
        expect(Math.abs((calendarBox.y + calendarBox.height / 2) - (menuBox.y + menuBox.height / 2)))
            .toBeLessThanOrEqual(1);
    }
});

test('mobile menu is focus-safe and closes with Escape when available', async ({page}) => {
    const toggle = page.locator('.mobile-menu-toggle');
    if (!(await toggle.isVisible())) {
        test.skip();
    }

    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('.mobile-menu-backdrop')).toBeVisible();
    await expect(page.locator('.sidebar-nav a').first()).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(toggle).toBeFocused();
});

test('past day is locked and can be temporarily unlocked', async ({page}) => {
    await page.goto('/mois/2026-08');

    const row = page.locator('.work-row[data-date="2026-08-04"]');
    await expect(row).toHaveAttribute('data-locked', '1');
    await expect(row.locator('[name="driving"]')).toBeDisabled();
    await expect(row.locator('.rest-toggle')).toBeDisabled();

    const unlock = row.locator('.day-lock-toggle');
    await expect(unlock).toHaveText('Déverrouiller');
    await unlock.click();
    await expect(row).toHaveAttribute('data-unlocked', '1');
    await expect(row.locator('[name="driving"]')).toBeEnabled();
    await expect(unlock).toHaveText('Verrouiller');

    await unlock.click();
    await expect(row).toHaveAttribute('data-unlocked', '0');
    await expect(row.locator('[name="driving"]')).toBeDisabled();
});

test('day editor saves a row and reports the new total', async ({page}) => {
    await page.goto('/mois/2026-08');
    const showMore = page.locator('#toggle-days-mobile');
    if (await showMore.isVisible()) {
        await showMore.click();
    }

    const row = page.locator('.work-row[data-date="2026-08-17"]');
    await expect(row).toBeVisible();
    await ensureEditable(row);
    await row.locator('.edit-day').click();
    await expect(page.locator('#day-dialog')).toBeVisible();

    const dialog = page.locator('#day-dialog');
    await dialog.locator('input[name="driving"]').fill('');
    await dialog.locator('input[name="warehouse"]').fill('');
    await expect(dialog.locator('#dialog-end')).toHaveText('');
    await dialog.locator('input[name="driving"]').fill('01:00');
    await expect(dialog.locator('#dialog-end')).toHaveText('08:45');

    const responsePromise = page.waitForResponse(response =>
        response.url().endsWith('/jours/2026-08-17') && response.request().method() === 'PUT',
    );
    await dialog.locator('#save-day').click();
    const response = await responsePromise;
    expect(response.ok()).toBeTruthy();

    await expect(row.locator('.total-cell strong')).toContainText('01:00');
    await expect(page.locator('#save-state')).toContainText(/Enregistré|Toutes les modifications/);
});

test('rest checkbox persists when checked and unchecked', async ({page}, testInfo) => {
    await page.goto('/mois/2026-08');
    const showMore = page.locator('#toggle-days-mobile');
    if (await showMore.isVisible()) {
        await showMore.click();
    }

    const dates = {
        'mobile-390': '2026-08-18',
        'tablet-768': '2026-08-19',
        'desktop-1440': '2026-08-20',
        'wide-2560': '2026-08-21',
    };
    const date = dates[testInfo.project.name];
    const row = page.locator(`.work-row[data-date="${date}"]`);
    const toggle = row.locator('.rest-toggle');
    await ensureEditable(row);
    await expect(toggle).not.toBeChecked();

    const responsePromise = page.waitForResponse(response =>
        response.url().endsWith(`/jours/${date}`) && response.request().method() === 'PUT',
    );
    await toggle.check();
    const response = await responsePromise;

    expect(response.ok()).toBeTruthy();
    await expect(toggle).toBeChecked();
    await expect(row).toHaveClass(/row-rest/);
    await expect(row.locator('.total-cell strong')).toHaveText('—');

    await page.reload();
    if (await showMore.isVisible()) {
        await showMore.click();
    }
    const reloadedRow = page.locator(`.work-row[data-date="${date}"]`);
    const reloadedToggle = reloadedRow.locator('.rest-toggle');
    await ensureEditable(reloadedRow);
    await expect(reloadedToggle).toBeChecked();

    const uncheckResponsePromise = page.waitForResponse(response =>
        response.url().endsWith(`/jours/${date}`) && response.request().method() === 'PUT',
    );
    await reloadedToggle.uncheck();
    const uncheckResponse = await uncheckResponsePromise;
    expect(uncheckResponse.ok()).toBeTruthy();
    await expect(reloadedToggle).not.toBeChecked();
    await expect(reloadedRow).not.toHaveClass(/row-rest/);

    await page.reload();
    if (await showMore.isVisible()) {
        await showMore.click();
    }
    const finalRow = page.locator(`.work-row[data-date="${date}"]`);
    await expect(finalRow.locator('.rest-toggle')).not.toBeChecked();
    await expect(finalRow).not.toHaveClass(/row-rest/);
});

test('rest checkbox supersedes a late keepalive from the previous page', async ({page}, testInfo) => {
    await page.goto('/mois/2026-08');
    const showMore = page.locator('#toggle-days-mobile');
    if (await showMore.isVisible()) {
        await showMore.click();
    }

    const dates = {
        'mobile-390': '2026-08-22',
        'tablet-768': '2026-08-24',
        'desktop-1440': '2026-08-25',
        'wide-2560': '2026-08-26',
    };
    const date = dates[testInfo.project.name];
    const row = page.locator(`.work-row[data-date="${date}"]`);
    const toggle = row.locator('.rest-toggle');
    await ensureEditable(row);
    const lateKeepaliveVersion = Date.now() - 1000;

    const preloadStatus = await page.evaluate(async ({date, lateKeepaliveVersion}) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const response = await fetch(`/jours/${date}`, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token},
            body: JSON.stringify({
                start_time: '07:45',
                driving: '01:00',
                warehouse: '',
                is_rest: false,
                meal_mode: 'auto',
                meal_amount: '',
                write_version: lateKeepaliveVersion,
                unlocked: true,
            }),
        });
        return response.status;
    }, {date, lateKeepaliveVersion});
    expect(preloadStatus).toBe(200);

    const successResponse = page.waitForResponse(response =>
        response.url().endsWith(`/jours/${date}`)
        && response.request().method() === 'PUT'
        && response.status() === 200,
    );
    await toggle.check();
    await successResponse;

    await expect(toggle).toBeChecked();
    await expect(row).toHaveClass(/row-rest/);
    const savedVersion = Number(await row.getAttribute('data-write-version'));
    expect(savedVersion).toBeGreaterThan(lateKeepaliveVersion);
    expect(savedVersion).toBeGreaterThanOrEqual(Date.now() - 2000);
});

test('weekly summary metrics stay inside their cards without overlap', async ({page}) => {
    await page.goto('/mois/2026-08');
    const cards = page.locator('.week-card');
    const cardCount = await cards.count();
    expect(cardCount).toBeGreaterThan(0);
    for (let index = 0; index < cardCount; index += 1) {
        const card = cards.nth(index);
        await expect(card.getByText('Montant heures sup :', {exact: true})).toBeVisible();
        await expect(card.locator('.green-value')).toContainText('net');
    }

    const layout = await page.locator('.week-card').evaluateAll(cards => cards.map(card => {
        const metrics = card.querySelector('.week-metrics');
        const children = metrics ? [...metrics.children] : [];
        const pairs = [];
        for (let index = 0; index + 1 < children.length; index += 2) {
            const label = children[index].getBoundingClientRect();
            const value = children[index + 1].getBoundingClientRect();
            pairs.push({labelRight: label.right, valueLeft: value.left});
        }
        return {overflow: card.scrollWidth - card.clientWidth, pairs};
    }));

    for (const card of layout) {
        expect(card.overflow).toBeLessThanOrEqual(1);
        for (const pair of card.pairs) expect(pair.labelRight).toBeLessThanOrEqual(pair.valueLeft + 1);
    }
});

test('payment history is simplified and editing uses a modal', async ({page}, testInfo) => {
    const dates = {
        'mobile-390': '2026-01-15',
        'tablet-768': '2026-02-15',
        'desktop-1440': '2026-03-15',
        'wide-2560': '2026-04-15',
    };
    const paymentDate = dates[testInfo.project.name];

    await page.goto('/paiements');
    await expect(page.getByRole('heading', {name: 'Historique des paiements d’heures supplémentaires'})).toBeVisible();
    await expect(page.locator('.payment-filters')).toHaveCount(0);
    await expect(page.getByText('Référence période')).toHaveCount(0);
    await expect(page.locator('.allocation-tags')).toHaveCount(0);

    const createForm = page.locator('.payment-form');
    await createForm.locator('input[name="payment_date"]').fill(paymentDate);
    await createForm.locator('input[name="amount"]').fill('12,34');
    await createForm.locator('input[name="hours_paid"]').fill('01:30');
    await Promise.all([
        page.waitForURL(/\/paiements$/),
        createForm.getByRole('button', {name: /Enregistrer le paiement/}).click(),
    ]);

    let paymentRow = page.locator('.payment-row').filter({hasText: '12,34 €'}).first();
    await expect(paymentRow).toBeVisible();
    await paymentRow.getByRole('button', {name: 'Modifier'}).click();

    const dialog = page.locator('#payment-edit-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('input[name="payment_date"]')).toHaveValue(paymentDate);
    await expect(dialog.locator('input[name="amount"]')).toHaveValue('12,34');
    await expect(dialog.locator('input[name="hours_paid"]')).toHaveValue('01:30');
    await dialog.getByRole('button', {name: 'Annuler'}).click();
    await expect(dialog).not.toBeVisible();

    await paymentRow.getByRole('button', {name: 'Modifier'}).click();
    await dialog.locator('input[name="amount"]').fill('13,45');
    await dialog.locator('input[name="hours_paid"]').fill('02:15');
    await Promise.all([
        page.waitForURL(/\/paiements$/),
        dialog.getByRole('button', {name: 'Enregistrer'}).click(),
    ]);

    paymentRow = page.locator('.payment-row').filter({hasText: '13,45 €'}).first();
    await expect(paymentRow).toBeVisible();
    await expect(paymentRow).toContainText('02:15');
    await expect(page.getByText('Référence période')).toHaveCount(0);
    await expect(page.locator('.allocation-tags')).toHaveCount(0);

    await paymentRow.getByRole('button', {name: 'Supprimer'}).click();
    await expect(page.locator('#confirm-dialog')).toBeVisible();
    await Promise.all([
        page.waitForURL(/\/paiements$/),
        page.locator('#confirm-dialog-submit').click(),
    ]);
    await expect(page.locator('.payment-row').filter({hasText: '13,45 €'})).toHaveCount(0);
});


test('salary tracking works across responsive layouts', async ({page}, testInfo) => {
    const months = {
        'mobile-390': '2026-01',
        'tablet-768': '2026-02',
        'desktop-1440': '2026-03',
        'wide-2560': '2026-04',
    };
    const month = months[testInfo.project.name];

    await page.goto('/salaires');
    await expect(page.getByRole('heading', {name: 'Enregistrer un salaire'})).toBeVisible();
    await expect(page.getByRole('heading', {name: 'Évolution des salaires'})).toBeVisible();
    await expect(page.getByRole('heading', {name: 'Historique des salaires'})).toBeVisible();
    await expect(page.locator('.salary-filters')).toHaveCount(0);
    await expect(page.locator('textarea[name="note"]')).toHaveCount(0);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    const chartPointsBefore = await page.locator('.salary-chart-point').count();

    await page.locator('input[name="month"]').fill(month);
    await page.locator('input[name="net_amount"]').fill('1850,50');
    await Promise.all([
        page.waitForURL(/\/salaires$/),
        page.getByRole('button', {name: /Enregistrer le salaire/}).click(),
    ]);

    await expect(page.getByText('1 850,50 €').first()).toBeVisible();
    await expect(page.locator('.salary-chart-point')).toHaveCount(chartPointsBefore + 1);

    const salaryRow = page.locator('.salary-table tbody tr').filter({has: page.locator(`a[href$="/mois/${month}"]`)});
    await expect(salaryRow).toBeVisible();
    await salaryRow.getByRole('button', {name: /Supprimer/}).click();
    await expect(page.locator('#confirm-dialog')).toBeVisible();
    await Promise.all([
        page.waitForURL(/\/salaires$/),
        page.locator('#confirm-dialog-submit').click(),
    ]);
    await expect(page.locator(`.salary-table a[href$="/mois/${month}"]`)).toHaveCount(0);
});


test('planning works across responsive layouts', async ({page}) => {
    await page.goto('/planning?view=month&date=2026-09-01');
    await expect(page.getByRole('heading', {name: 'Planning', exact: true})).toBeVisible();
    await expect(page.locator('.planning-view-switch a')).toHaveCount(3);
    await expect(page.locator('.mobile-nav a')).toHaveCount(7);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);

    const day = page.locator('.planning-day[data-date="2026-09-14"]');
    await expect(day).toBeVisible();
    await day.locator('.planning-edit summary').click();
    const form = day.locator('.planning-edit form');
    await form.locator('input[name="planned"]').fill('07:30');
    await Promise.all([
        page.waitForURL(/\/planning\?view=month&date=2026-09-01$/),
        form.getByRole('button', {name: 'Enregistrer'}).click(),
    ]);
    await expect(day.locator('.planning-hours')).toContainText('07:30');

    await page.getByRole('link', {name: 'Semaine', exact: true}).click();
    await expect(page).toHaveURL(/\/planning\?view=week&date=2026-09-01$/);
    await expect(page.locator('.planning-grid-week')).toBeVisible();
});


test('document library works across responsive layouts', async ({page}, testInfo) => {
    const rootName = `E2E ${testInfo.project.name}`;
    const imageName = `photo-${testInfo.project.name}.png`;
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZVZ8AAAAASUVORK5CYII=', 'base64');

    await page.goto('/bibliotheque');
    await expect(page.getByRole('heading', {name: 'Documents', exact: true})).toBeVisible();
    await expect(page.getByText('Aucun dossier n’est créé automatiquement.')).toBeVisible();
    await expect(page.locator('.library-upload-form')).toHaveCount(0);
    await expect(page.getByText('Corbeille', {exact: true})).toBeVisible();

    const rootToolbarControls = page.locator('.library-toolbar-button');
    await expect(rootToolbarControls).toHaveCount(2);
    const rootToolbarMetrics = await rootToolbarControls.evaluateAll(elements => elements.map(element => {
        const style = getComputedStyle(element);
        return {
            height: element.getBoundingClientRect().height,
            fontSize: style.fontSize,
            fontWeight: style.fontWeight,
            borderRadius: style.borderRadius,
        };
    }));
    expect(rootToolbarMetrics[0]).toEqual(rootToolbarMetrics[1]);

    let overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);

    const createDetails = page.locator('.library-create-details');
    await page.getByText('Nouveau dossier', {exact: true}).click();
    await expect(createDetails).toHaveAttribute('open', '');
    await page.locator('.library-content-panel').click({position: {x: 10, y: 10}});
    await expect(createDetails).not.toHaveAttribute('open', '');

    await page.getByText('Nouveau dossier', {exact: true}).click();
    const createForm = page.locator('.library-popover-form');
    await createForm.locator('input[name="name"]').fill(rootName);
    await Promise.all([
        page.waitForURL(/\/bibliotheque$/),
        createForm.getByRole('button', {name: 'Créer le dossier'}).click(),
    ]);

    let rootCard = page.locator('.library-folder-card').filter({hasText: rootName});
    await expect(rootCard).toBeVisible();
    await rootCard.locator('.library-item-menu summary').click();
    await expect(rootCard.locator('.library-item-menu')).toHaveAttribute('open', '');
    await page.locator('.library-title-block').click();
    await expect(rootCard.locator('.library-item-menu')).not.toHaveAttribute('open', '');

    await rootCard.locator('.library-folder-link').click();
    await expect(page.locator('.library-current-folder')).toContainText(rootName);
    await expect(page.getByText('Ajouter un document', {exact: true})).toBeVisible();

    const folderToolbarControls = page.locator('.library-toolbar-button');
    await expect(folderToolbarControls).toHaveCount(3);
    const folderToolbarMetrics = await folderToolbarControls.evaluateAll(elements => elements.map(element => {
        const style = getComputedStyle(element);
        return {
            height: element.getBoundingClientRect().height,
            fontSize: style.fontSize,
            fontWeight: style.fontWeight,
            borderRadius: style.borderRadius,
        };
    }));
    expect(new Set(folderToolbarMetrics.map(metrics => JSON.stringify(metrics))).size).toBe(1);

    await page.getByText('Nouveau dossier', {exact: true}).click();
    await page.locator('.library-popover-form input[name="name"]').fill('Août');
    await Promise.all([
        page.waitForURL(/\/bibliotheque\/\d+$/),
        page.locator('.library-popover-form').getByRole('button', {name: 'Créer le dossier'}).click(),
    ]);

    const child = page.locator('.library-folder-card').filter({hasText: 'Août'});
    await expect(child).toBeVisible();
    await child.locator('.library-folder-link').click();
    await expect(page.locator('.library-breadcrumbs')).toContainText(rootName);
    await expect(page.locator('.library-breadcrumbs')).toContainText('Août');

    const uploadResponse = page.waitForResponse(response =>
        response.url().endsWith('/bibliotheque/fichiers') && response.request().method() === 'POST',
    );
    await page.locator('.library-upload-form input[type="file"]').setInputFiles({
        name: imageName,
        mimeType: 'image/png',
        buffer: png,
    });
    expect((await uploadResponse).status()).toBe(302);
    await expect(page.getByText(imageName, {exact: true})).toBeVisible();

    overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);

    await page.goto('/bibliotheque');
    rootCard = page.locator('.library-folder-card').filter({hasText: rootName});
    await rootCard.locator('.library-item-menu summary').click();
    const deleteForm = rootCard.locator('form[data-confirm]');
    const deleteButton = deleteForm.getByRole('button', {name: 'Mettre à la corbeille'});
    await deleteButton.click();
    await expect(page.locator('#confirm-dialog')).toBeVisible();
    await Promise.all([
        page.waitForURL(/\/bibliotheque$/),
        page.locator('#confirm-dialog-submit').click(),
    ]);
    await expect(page.getByText(rootName, {exact: true})).toHaveCount(0);

    await page.getByRole('link', {name: /Corbeille/}).click();
    await expect(page.getByRole('heading', {name: 'Corbeille'})).toBeVisible();
    let trashCard = page.locator('.library-trash-card').filter({hasText: rootName});
    await expect(trashCard).toBeVisible();
    await Promise.all([
        page.waitForURL(/\/bibliotheque\/corbeille$/),
        trashCard.getByRole('button', {name: 'Restaurer'}).click(),
    ]);
    await expect(page.getByText(rootName, {exact: true})).toHaveCount(0);

    await page.goto('/bibliotheque');
    rootCard = page.locator('.library-folder-card').filter({hasText: rootName});
    await expect(rootCard).toBeVisible();
    await rootCard.locator('.library-item-menu summary').click();
    const secondDeleteForm = rootCard.locator('form[data-confirm]');
    await secondDeleteForm.getByRole('button', {name: 'Mettre à la corbeille'}).click();
    await expect(page.locator('#confirm-dialog')).toBeVisible();
    await Promise.all([
        page.waitForURL(/\/bibliotheque$/),
        page.locator('#confirm-dialog-submit').click(),
    ]);

    await page.getByRole('link', {name: /Corbeille/}).click();
    trashCard = page.locator('.library-trash-card').filter({hasText: rootName});
    const permanentForm = trashCard.locator('form[data-confirm]');
    const permanentButton = permanentForm.getByRole('button', {name: 'Supprimer définitivement'});
    await permanentButton.click();
    await expect(page.locator('#confirm-dialog')).toBeVisible();
    await Promise.all([
        page.waitForURL(/\/bibliotheque\/corbeille$/),
        page.locator('#confirm-dialog-submit').click(),
    ]);
    await expect(page.getByText(rootName, {exact: true})).toHaveCount(0);
});
