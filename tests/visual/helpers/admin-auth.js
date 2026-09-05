const adminUser = process.env.DESKHELPER_LEGACY_ADMIN_USER || 'admin';
const adminPassword = process.env.DESKHELPER_LEGACY_ADMIN_PASSWORD || 'admin123';

async function loginAsAdmin(page)
{
    await page.goto('/deskhelper/admin/login');
    await page.waitForLoadState('networkidle');

    await page.getByLabel('Usuário').fill(adminUser);
    await page.getByLabel('Senha').fill(adminPassword);
    await page.getByRole('button', { name: 'Entrar' }).click();
}

module.exports = {
    adminUser,
    adminPassword,
    loginAsAdmin,
};
