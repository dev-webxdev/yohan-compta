const {defineConfig} = require('@playwright/test');

const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8010';

module.exports = defineConfig({
    testDir: './tests/browser',
    timeout: 30_000,
    expect: {timeout: 5_000},
    fullyParallel: false,
    workers: 1,
    reporter: process.env.CI ? 'github' : 'line',
    use: {
        baseURL,
        browserName: 'chromium',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'bash tests/browser/start-server.sh',
        url: `${baseURL}/connexion`,
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
    },
    projects: [
        {
            name: 'mobile-390',
            use: {viewport: {width: 390, height: 844}},
        },
        {
            name: 'tablet-768',
            use: {viewport: {width: 768, height: 1024}},
        },
        {
            name: 'desktop-1440',
            use: {viewport: {width: 1440, height: 900}},
        },
        {
            name: 'wide-2560',
            use: {viewport: {width: 2560, height: 1440}},
        },
    ],
});
