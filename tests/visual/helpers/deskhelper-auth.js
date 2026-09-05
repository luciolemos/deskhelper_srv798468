const deskhelperBaseUrl = (process.env.DESKHELPER_BASE_URL || 'http://127.0.0.1:8086/deskhelper').replace(/\/$/, '');
const deskhelperAdminEmail = process.env.DESKHELPER_ADMIN_EMAIL || 'admin@deskhelper.local';
const deskhelperAdminPassword = process.env.DESKHELPER_ADMIN_PASSWORD || 'admin123';

async function loginAsDeskhelperAdmin(page)
{
    await page.goto(`${deskhelperBaseUrl}/login`);
    await page.waitForLoadState('networkidle');

    await page.getByLabel('E-mail').fill(deskhelperAdminEmail);
    await page.getByLabel('Senha', { exact: true }).fill(deskhelperAdminPassword);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await page.waitForURL(/\/deskhelper\/app$/);
}

module.exports = {
    deskhelperBaseUrl,
    deskhelperAdminEmail,
    deskhelperAdminPassword,
    loginAsDeskhelperAdmin,
};
