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

test('day editor saves a row and reports the new total', async ({page}) => {
    await page.goto('/mois/2026-08');
    const showMore = page.locator('#toggle-days-mobile');
    if (await showMore.isVisible()) {
        await showMore.click();
    }

    const row = page.locator('.work-row[data-date="2026-08-17"]');
    await expect(row).toBeVisible();
    await row.locator('.edit-day').click();
    await expect(page.locator('#day-dialog')).toBeVisible();

    const dialog = page.locator('#day-dialog');
    await dialog.locator('input[name="driving"]').fill('01:00');
    await dialog.locator('input[name="warehouse"]').fill('');

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

test('payment history is shown without filter controls', async ({page}) => {
    await page.goto('/paiements');
    await expect(page.getByRole('heading', {name: 'Historique des paiements d’heures supplémentaires'})).toBeVisible();
    await expect(page.locator('.payment-filters')).toHaveCount(0);
});
