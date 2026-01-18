import { test, expect, Page } from '@playwright/test';

/**
 * RBAC Visibility Tests
 * 
 * Tests that UI elements are correctly shown/hidden based on user roles,
 * and that attempts to access forbidden actions are properly denied.
 */

const BASE_URL = 'http://localhost:8888/4erp/4erpv2';

interface TestUser {
    username: string;
    password: string;
    role: string;
}

// Test users per role (seeded by scripts/seed_rbac_test_users.php)
const TEST_USERS: Record<string, TestUser> = {
    ADM: { username: 'admin', password: 'Test1234!', role: 'ADM' },
    PLN: { username: 'planner', password: 'Test1234!', role: 'PLN' },
    PUR: { username: 'purchaser', password: 'Test1234!', role: 'PUR' },
    WH: { username: 'warehouse', password: 'Test1234!', role: 'WH' },
    ACC: { username: 'accountant', password: 'Test1234!', role: 'ACC' },
    MGR: { username: 'manager', password: 'Test1234!', role: 'MGR' },
};

async function loginAs(page: Page, role: string) {
    const user = TEST_USERS[role];
    if (!user) throw new Error(`Unknown role: ${role}`);

    await page.goto(BASE_URL + '/modules/auth/login.php');
    await page.fill('input[name="username"]', user.username);
    await page.fill('input[name="password"]', user.password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/index.php', { timeout: 10000 });
}

test.describe('RBAC Visibility - ADM (Admin)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAs(page, 'ADM');
    });

    test('ADM can see Admin menu', async ({ page }) => {
        await page.goto(BASE_URL + '/index.php');
        await expect(page.locator('nav >> text=Admin')).toBeVisible();
    });

    test('ADM can access Jobs', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/jobs/');
        await expect(page.locator('h2, h1')).toContainText(/Jobs/i);
    });

    test('ADM can access Procurement', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/');
        await expect(page.locator('h1, h2')).toContainText(/Procurement/i);
    });
});

test.describe('RBAC Visibility - WH (Warehouse)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAs(page, 'WH');
    });

    test('WH cannot see Admin menu', async ({ page }) => {
        await page.goto(BASE_URL + '/index.php');
        // WH should not have Admin in the visible nav
        const adminLink = page.locator('nav >> text=Admin');
        await expect(adminLink).toHaveCount(0);
    });

    test('WH can access GR (Goods Receipt)', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/gr/');
        // Should load successfully (WH can create GR per Policy)
        await expect(page).not.toHaveURL(/login/);
    });
});

test.describe('RBAC Visibility - PLN (Planner)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAs(page, 'PLN');
    });

    test('PLN can access Planning', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/planning/');
        await expect(page.locator('h1, h2')).toContainText(/Planning/i);
    });

    test('PLN can access Routes', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/logistics/routes/');
        await expect(page).not.toHaveURL(/login/);
    });
});

test.describe('RBAC Visibility - PUR (Procurement)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAs(page, 'PUR');
    });

    test('PUR can see Procurement menu', async ({ page }) => {
        await page.goto(BASE_URL + '/index.php');
        const procLink = page.locator('nav >> text=Procurement');
        await expect(procLink).toBeVisible();
    });

    test('PUR can access PR list', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/pr/');
        await expect(page).not.toHaveURL(/login/);
    });

    test('PUR can access PO list', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/po/');
        await expect(page).not.toHaveURL(/login/);
    });
});

test.describe('RBAC Visibility - ACC (Accounting)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAs(page, 'ACC');
    });

    test('ACC can see PO (for invoice verification)', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/po/');
        // ACC can view PO per Policy matrix
        await expect(page).not.toHaveURL(/login/);
    });
});

test.describe('RBAC Visibility - MGR (Manager)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAs(page, 'MGR');
    });

    test('MGR can see Admin menu', async ({ page }) => {
        await page.goto(BASE_URL + '/index.php');
        await expect(page.locator('nav >> text=Admin')).toBeVisible();
    });

    test('MGR can access Audit Logs', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/admin/audit_logs.php');
        await expect(page.locator('h1, h2')).toContainText(/Audit/i);
    });
});
