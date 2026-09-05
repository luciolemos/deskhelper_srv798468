const { test, expect } = require('@playwright/test');
const { deskhelperBaseUrl, loginAsDeskhelperAdmin } = require('./helpers/deskhelper-auth');

test('login lock survives a new browser session and displays the retry message', async ({ page, browser }, testInfo) => {
  const email = `locked-${testInfo.project.name}-${Date.now()}@example.test`;
  await page.goto(`${deskhelperBaseUrl}/login`);
  for (let attempt = 1; attempt <= 5; attempt++) {
    await page.getByLabel('E-mail', { exact: true }).fill(email);
    await page.getByLabel('Senha', { exact: true }).fill('incorrect-password');
    const responsePromise = page.waitForResponse(response => response.url().endsWith('/login') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    const response = await responsePromise;
    expect(response.status()).toBe(attempt < 5 ? 200 : 429);
  }
  await expect(page.getByRole('alert')).toContainText('Muitas tentativas de acesso.');
  const freshContext = await browser.newContext({
    viewport: testInfo.project.use.viewport, isMobile: testInfo.project.use.isMobile,
    hasTouch: testInfo.project.use.hasTouch,
  });
  try {
    const freshPage = await freshContext.newPage();
    await freshPage.goto(`${deskhelperBaseUrl}/login`);
    await freshPage.getByLabel('E-mail', { exact: true }).fill(email.toUpperCase());
    await freshPage.getByLabel('Senha', { exact: true }).fill('incorrect-password');
    const responsePromise = freshPage.waitForResponse(response => response.url().endsWith('/login') && response.request().method() === 'POST');
    await freshPage.getByRole('button', { name: 'Entrar', exact: true }).click();
    const response = await responsePromise;
    expect(response.status()).toBe(429);
    expect(Number(response.headers()['retry-after'])).toBeGreaterThan(0);
    await expect(freshPage.getByRole('alert')).toContainText('Tente novamente em');
  } finally {
    await freshContext.close();
  }
});

test('last administrator keeps access after a rejected profile change', async ({ page }, testInfo) => {
  await loginAsDeskhelperAdmin(page);
  await page.goto(`${deskhelperBaseUrl}/admin/users?view=list`);
  const filters = page.locator('.dh-admin-users-filter-grid');
  for (const name of ['name', 'email']) {
    const box = await filters.locator(`input[name="${name}"]`).boundingBox();
    expect(box.width).toBeGreaterThan(120);
  }
  await filters.getByRole('textbox', { name: 'Nome', exact: true }).fill('admin');
  await filters.getByRole('textbox', { name: 'E-mail', exact: true }).fill('admin@deskhelper.local');
  await page.getByRole('button', { name: 'Filtrar', exact: true }).click();
  const form = page.locator('form[action$="/admin/users/update"]:has(input[name="id"][value="1"])');
  await form.locator('select[name="role"]').selectOption('user');
  await form.getByRole('button', { name: 'Salvar', exact: true }).click();
  await expect(page.getByText('É necessário manter pelo menos um administrador ativo.', { exact: true })).toBeVisible();
  await page.goto(`${deskhelperBaseUrl}/admin/users?view=list`);
  await expect(form.locator('select[name="role"]')).toHaveValue('admin');
  await expect(form.locator('select[name="status"]')).toHaveValue('active');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('users.png'), fullPage: true });
});
