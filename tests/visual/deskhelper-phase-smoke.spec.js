const { test, expect } = require('@playwright/test');
const { deskhelperBaseUrl, loginAsDeskhelperAdmin } = require('./helpers/deskhelper-auth');

test.describe('deskhelper phase smoke', () => {
    test('register, approve and resolve ticket flow works', async ({ browser }, testInfo) => {
        const stamp = Date.now();
        const userEmail = `smoke.${stamp}@example.com`;
        const userPassword = 'secret123';

        const userContext = await browser.newContext({ viewport: testInfo.project.use.viewport, isMobile: testInfo.project.use.isMobile, hasTouch: testInfo.project.use.hasTouch, ignoreHTTPSErrors: true });
        const userPage = await userContext.newPage();

        await userPage.goto(`${deskhelperBaseUrl}/register`);
        await userPage.locator('input[name="name"]').fill('Smoke User');
        await userPage.getByLabel('E-mail').fill(userEmail);
        await userPage.locator('select[name="rank"]').selectOption('Capitão');
        await userPage.locator('select[name="sector"]').selectOption('NPOR');
        await userPage.getByLabel('Telefone').fill('84999999999');
        await userPage.getByLabel('Senha', { exact: true }).fill(userPassword);
        await userPage.getByLabel('Confirmar senha').fill(userPassword);
        await userPage.getByRole('button', { name: 'Cadastrar' }).click();
        await userPage.waitForURL(/\/deskhelper\/register\/pending$/);

        const adminContext = await browser.newContext({ viewport: testInfo.project.use.viewport, isMobile: testInfo.project.use.isMobile, hasTouch: testInfo.project.use.hasTouch, ignoreHTTPSErrors: true });
        const adminPage = await adminContext.newPage();
        await loginAsDeskhelperAdmin(adminPage);
        await adminPage.goto(`${deskhelperBaseUrl}/admin/users/pending?view=list`);
        const row = adminPage.locator('tr', { hasText: userEmail });
        await expect(row).toBeVisible();
        const operatorId = await row.locator('input[name="id"]').first().inputValue();
        await row.locator('select[name="role"]').selectOption('operator');
        await row.getByRole('button', { name: 'Aprovar' }).click();
        await adminPage.waitForURL(/\/deskhelper\/admin\/users\/pending(?:\?.*)?$/);

        await userPage.goto(`${deskhelperBaseUrl}/login`);
        await userPage.getByLabel('E-mail').fill(userEmail);
        await userPage.getByLabel('Senha', { exact: true }).fill(userPassword);
        await userPage.getByRole('button', { name: 'Entrar' }).click();
        await userPage.waitForURL(/\/deskhelper\/app$/);

        await userPage.goto(`${deskhelperBaseUrl}/tickets/new`);
        await userPage.getByLabel('Título').fill('Smoke Ticket');
        await userPage.locator('select[name="category_id"]').selectOption({ index: 1 });
        await userPage.locator('select[name="priority"]').selectOption('high');
        await userPage.locator('select[name="request_sector"]').selectOption('NPOR');
        await userPage.getByLabel('Descrição').fill('Fluxo automatizado do deskhelper.');
        await userPage.getByRole('button', { name: 'Abrir ticket' }).click();
        await expect(userPage).toHaveURL(/\/deskhelper\/tickets\/\d+$/);
        await expect(userPage.getByRole('heading', { name: 'Smoke Ticket', exact: true })).toBeVisible();

        const ticketUrl = userPage.url();
        await adminPage.goto(ticketUrl);
        await adminPage.locator('select[name="assigned_to_user_id"]').selectOption(operatorId);
        await adminPage.locator('select[name="status"]').selectOption('in_progress');
        await adminPage.getByRole('button', { name: 'Salvar fluxo', exact: true }).click();
        await adminPage.waitForURL(/\/deskhelper\/tickets\?view=list$/);

        await userPage.goto(ticketUrl);
        await userPage.locator('textarea[name="body"]').fill('Atendimento concluído pelo operador.');
        await userPage.getByRole('button', { name: 'Comentar', exact: true }).click();
        await expect(userPage.getByText('Atendimento concluído pelo operador.', { exact: true })).toBeVisible();
        await userPage.locator('select[name="status"]').selectOption('resolved');
        await userPage.getByRole('button', { name: 'Salvar atendimento', exact: true }).click();
        await userPage.waitForURL(/\/deskhelper\/tickets\?view=list$/);
        await adminPage.goto(ticketUrl);
        await expect(adminPage.locator('select[name="status"]')).toHaveValue('resolved');

        await userContext.close();
        await adminContext.close();
    });
});
