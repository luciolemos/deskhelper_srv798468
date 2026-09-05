const { test, expect } = require('@playwright/test');
const { deskhelperBaseUrl, loginAsDeskhelperAdmin } = require('./helpers/deskhelper-auth');

async function checkPasswordFields(page) {
  const inputs = page.locator('[data-password-input]');
  for (let index = 0; index < await inputs.count(); index++) {
    const input = inputs.nth(index);
    const toggle = input.locator('..').locator('[data-password-toggle-icon]');
    await expect(input).toHaveAccessibleName(/Senha|senha/);
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await toggle.focus();
    await page.keyboard.press('Space');
    await expect(input).toHaveAttribute('type', 'text');
    await expect(toggle).toHaveAttribute('aria-label', 'Ocultar senha');
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await page.keyboard.press('Enter');
    await expect(input).toHaveAttribute('type', 'password');
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
  }
}

test('login and registration have usable password controls and fit the viewport', async ({ page }, testInfo) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  for (const path of ['/login', '/register']) {
    await page.goto(`${deskhelperBaseUrl}${path}`);
    await expect(page.locator('.dh-auth-brand .db-brand')).toHaveText('16º BI Mtz');
    await checkPasswordFields(page);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    await page.screenshot({ path: testInfo.outputPath(`${path.slice(1)}.png`), fullPage: true });
  }
  expect(errors).toEqual([]);
});

test('authenticated account password controls and navigation work', async ({ page }, testInfo) => {
  await loginAsDeskhelperAdmin(page);
  await page.goto(`${deskhelperBaseUrl}/account`);
  await expect(page.locator('[data-password-input]')).toHaveCount(4);
  await checkPasswordFields(page);
  await page.screenshot({ path: testInfo.outputPath('account.png'), fullPage: true });
  for (const path of ['/guide', '/help', '/tickets?view=list', '/admin/users?view=list', '/admin/categories?view=list']) {
    const response = await page.goto(`${deskhelperBaseUrl}${path}`);
    expect(response.status()).toBe(200);
    await expect(page.locator('#db-content')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);

    const tableWrapper = page.locator('.db-table-wrapper');
    if (await tableWrapper.count()) {
      const wrapperBox = await tableWrapper.first().boundingBox();
      expect(wrapperBox.x).toBeGreaterThanOrEqual(0);
      expect(wrapperBox.x + wrapperBox.width).toBeLessThanOrEqual(testInfo.project.use.viewport.width + 1);
    }
  }
});

test('all overview cards open a view with a return shortcut', async ({ page }) => {
  await loginAsDeskhelperAdmin(page);
  await page.goto(`${deskhelperBaseUrl}/app?panel=overview`);

  const cardLinks = await page.locator('.dh-kpi-link').evaluateAll(links => links.map(link => link.href));
  expect(cardLinks).toHaveLength(9);

  for (const href of cardLinks) {
    await page.goto(href);
    const returnLink = page.locator('[data-overview-return]');
    await expect(returnLink).toBeVisible();
    await expect(returnLink).toHaveAttribute('href', /\/deskhelper\/app\?panel=overview$/);
  }
});

test('sidebar logout ends the authenticated session', async ({ page }) => {
  await loginAsDeskhelperAdmin(page);
  await page.goto(`${deskhelperBaseUrl}/app`);

  const sidebar = page.locator('[data-sidebar]');
  if (await page.evaluate(() => window.matchMedia('(max-width: 1100px)').matches)) {
    await page.locator('[data-sidebar-toggle]').click();
    await expect(page.locator('.db-shell')).toHaveClass(/is-sidebar-open/);
  }

  const logout = page.locator('[data-sidebar-logout]');
  await expect(logout).toBeVisible();
  await logout.click();
  await expect(page).toHaveURL(/\/deskhelper\/login$/);
});

test('legacy card preview opens, closes and returns focus', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(`${deskhelperBaseUrl}/admin/login`);
  await page.getByLabel('Usuário', { exact: true }).fill('admin');
  await page.getByLabel('Senha', { exact: true }).fill('admin123');
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await page.goto(`${deskhelperBaseUrl}/admin?entity=cards`);
  const preview = page.getByRole('button', { name: 'Visualizar card', exact: true }).first();
  await preview.click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('link', { name: 'Editar card', exact: true })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(preview).toBeFocused();
  await preview.click();
  await dialog.getByRole('button', { name: 'Fechar', exact: true }).click();
  await expect(dialog).toBeHidden();
  expect(errors).toEqual([]);
});
