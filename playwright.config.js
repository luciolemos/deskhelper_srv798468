const { defineConfig } = require('@playwright/test');

const useExternalBaseUrl = process.env.DESKHELPER_BASE_URL && process.env.DESKHELPER_BASE_URL !== '';

module.exports = defineConfig({
  testDir: './tests/visual',
  testIgnore: process.env.DESKHELPER_LEGACY_TESTS ? [] : ['**/legacy/**'],
  workers: 1,
  timeout: 30_000,
  expect: {
    timeout: 10_000,
    toHaveScreenshot: {
      maxDiffPixelRatio: 0.02,
    },
  },
  fullyParallel: true,
  reporter: [['list']],
  use: {
    baseURL: useExternalBaseUrl ? process.env.DESKHELPER_BASE_URL : 'http://127.0.0.1:8086/deskhelper/',
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    {
      name: 'mobile',
      use: {
        browserName: 'chromium',
        viewport: { width: 390, height: 844 },
        isMobile: true,
        hasTouch: true,
      },
    },
    {
      name: 'tablet',
      use: {
        browserName: 'chromium',
        viewport: { width: 820, height: 1180 },
        isMobile: false,
        hasTouch: true,
      },
    },
    {
      name: 'desktop',
      use: {
        browserName: 'chromium',
        viewport: { width: 1440, height: 900 },
      },
    },
  ],
  webServer: useExternalBaseUrl
    ? undefined
    : {
        command: 'composer start:visual',
        url: 'http://127.0.0.1:8086/deskhelper/login',
        reuseExistingServer: false,
        timeout: 120_000,
      },
});
