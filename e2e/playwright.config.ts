import { defineConfig, devices } from '@playwright/test'

const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:5174'
const skipWebServer = process.env.E2E_SKIP_WEBSERVER === '1'

export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL,
    trace: 'on-first-retry',
    ...devices['Desktop Chrome'],
  },
  webServer: skipWebServer
    ? undefined
    : {
        command: 'npm --prefix ../frontend/pos run dev',
        url: baseURL,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        env: {
          ...process.env,
          VITE_PROXY_TARGET: process.env.E2E_API_URL ?? 'http://127.0.0.1:8001',
        },
      },
})
