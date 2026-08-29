import { test, expect } from '@playwright/test'

const email = process.env.E2E_USER_EMAIL ?? 'admin@retail.local'
const password = process.env.E2E_USER_PASSWORD ?? 'password'
const storeName = process.env.E2E_STORE_NAME ?? 'Demo Store'

test.describe('POS critical floor flow', () => {
  test.skip(
    !process.env.E2E_API_URL && !process.env.CI,
    'Set E2E_API_URL or run in CI with API stack',
  )

  test('cashier signs in, picks store, and sees sync badge', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill(password)
    await page.getByRole('button', { name: 'Sign in' }).click()

    await expect(page.getByRole('heading', { name: 'Choose a store' })).toBeVisible({
      timeout: 15_000,
    })
    await page.getByRole('button', { name: storeName }).click()

    await expect(page.getByRole('button', { name: /ONLINE|OFFLINE|SYNCING|SYNC ERROR/ })).toBeVisible({
      timeout: 15_000,
    })
  })
})
