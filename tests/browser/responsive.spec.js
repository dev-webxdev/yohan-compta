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

test('payment history exposes lightweight filters', async ({page}) => {
    await page.goto('/paiements');
    await expect(page.getByRole('heading', {name: 'Historique des paiements d’heures supplémentaires'})).toBeVisible();
    await expect(page.locator('.payment-filters select[name="year"]')).toBeVisible();
    await expect(page.locator('.payment-filters input[name="q"]')).toBeVisible();
});
