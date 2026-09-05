const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('../helpers/admin-auth');

test.describe('Sidebar active state consistency', () => {
    const assertSingleActive = async(page, route) => {
        await page.goto(route);
        await page.waitForLoadState('networkidle');

        await expect(page.locator('.db-menu-item.is-active')).toHaveCount(1);
        await expect(page.locator('.db-menu-item[aria-current="page"]')).toHaveCount(1);
    };

    test('public routes keep exactly one active sidebar item', async({ page }) => {
        await assertSingleActive(page, '/deskhelper');
        await assertSingleActive(page, '/deskhelper/readme');
        await assertSingleActive(page, '/deskhelper/contato');
        await assertSingleActive(page, '/deskhelper/login');
    });

  test('admin routes keep exactly one active sidebar item', async({ page }) => {
        await loginAsAdmin(page);
        await page.waitForURL('**/deskhelper/admin**');

        await assertSingleActive(page, '/deskhelper/admin?entity=sections');
        await assertSingleActive(page, '/deskhelper/admin?entity=cards');
    });
});
