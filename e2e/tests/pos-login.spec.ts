import { test, expect } from '@playwright/test'

test.describe('POS login shell', () => {
  test('renders sign-in form without API', async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByRole('heading', { name: 'POS sign in' })).toBeVisible()
    await expect(page.getByLabel('Email')).toBeVisible()
    await expect(page.getByLabel('Password')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible()
  })
})
