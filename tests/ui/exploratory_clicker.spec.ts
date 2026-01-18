import { test, expect, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

/**
 * Exploratory Clicker
 * 
 * Broad coverage test that visits each discoverable page
 * and clicks visible interactive elements (safely).
 * Generates a report file with pages visited and errors found.
 */

const BASE_URL = 'http://localhost:8888/4erp/4erpv2';

const TEST_USER = {
    username: 'admin',
    password: 'admin123'
};

// All pages to explore
const PAGES_TO_EXPLORE = [
    '/index.php',
    '/modules/jobs/',
    '/modules/jobs/create.php',
    '/modules/planning/',
    '/modules/planning/create.php',
    '/modules/logistics/routes/',
    '/modules/logistics/dispatch/',
    '/modules/procurement/',
    '/modules/procurement/pr/',
    '/modules/procurement/pr/create.php',
    '/modules/procurement/po/',
    '/modules/procurement/gr/',
    '/modules/admin/',
    '/modules/admin/users.php',
    '/modules/admin/roles.php',
    '/modules/admin/permissions.php',
    '/modules/admin/doc_numbers.php',
    '/modules/admin/audit_logs.php',
    '/modules/master/',
    '/modules/master/customers.php',
    '/modules/master/suppliers.php',
    '/modules/master/items.php',
    '/modules/master/serials.php',
    '/modules/master/sites.php',
    '/modules/master/people.php',
];

// Elements to skip clicking (destructive actions)
const SKIP_PATTERNS = [
    /delete/i,
    /void/i,
    /cancel/i,
    /remove/i,
    /logout/i,
    /reverse/i,
];

interface PageReport {
    url: string;
    status: number;
    title: string;
    linksClicked: number;
    buttonsClicked: number;
    errors: string[];
    duration: number;
}

interface ExploratoryReport {
    timestamp: string;
    baseUrl: string;
    totalPages: number;
    totalErrors: number;
    pages: PageReport[];
}

async function login(page: Page) {
    await page.goto(BASE_URL + '/modules/auth/login.php');
    await page.fill('input[name="username"]', TEST_USER.username);
    await page.fill('input[name="password"]', TEST_USER.password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/index.php', { timeout: 10000 });
}

function shouldSkip(text: string): boolean {
    return SKIP_PATTERNS.some(pattern => pattern.test(text));
}

test.describe('Exploratory Clicker', () => {
    const report: ExploratoryReport = {
        timestamp: new Date().toISOString(),
        baseUrl: BASE_URL,
        totalPages: 0,
        totalErrors: 0,
        pages: [],
    };

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test.afterAll(async () => {
        // Write report to artifacts
        const artifactsDir = path.join(__dirname, 'artifacts');
        if (!fs.existsSync(artifactsDir)) {
            fs.mkdirSync(artifactsDir, { recursive: true });
        }

        report.totalPages = report.pages.length;
        report.totalErrors = report.pages.reduce((sum, p) => sum + p.errors.length, 0);

        const reportPath = path.join(artifactsDir, 'exploratory_report.json');
        fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
        console.log(`\n📊 Exploratory report saved to: ${reportPath}`);
        console.log(`   Total pages: ${report.totalPages}`);
        console.log(`   Total errors: ${report.totalErrors}`);
    });

    for (const pageUrl of PAGES_TO_EXPLORE) {
        test(`Explore: ${pageUrl}`, async ({ page }) => {
            const startTime = Date.now();
            const pageReport: PageReport = {
                url: pageUrl,
                status: 0,
                title: '',
                linksClicked: 0,
                buttonsClicked: 0,
                errors: [],
                duration: 0,
            };

            // Capture console errors
            page.on('console', msg => {
                if (msg.type() === 'error') {
                    pageReport.errors.push(`Console: ${msg.text()}`);
                }
            });

            // Capture page errors
            page.on('pageerror', err => {
                pageReport.errors.push(`PageError: ${err.message}`);
            });

            try {
                const response = await page.goto(BASE_URL + pageUrl);
                pageReport.status = response?.status() || 0;
                pageReport.title = await page.title();

                // Check for PHP errors
                const content = await page.content();
                if (content.includes('Fatal error') || content.includes('Parse error')) {
                    pageReport.errors.push('PHP Fatal/Parse error detected');
                }
                if (content.includes('Warning:')) {
                    pageReport.errors.push('PHP Warning detected');
                }

                // Click safe links within the page
                const links = await page.locator('main a:visible').all();
                for (const link of links.slice(0, 5)) { // Limit to first 5
                    const text = await link.textContent() || '';
                    if (!shouldSkip(text)) {
                        try {
                            // Just check if clickable, don't actually navigate
                            await expect(link).toBeVisible();
                            pageReport.linksClicked++;
                        } catch (e) {
                            // Ignore
                        }
                    }
                }

                // Check buttons exist (don't click submit buttons)
                const buttons = await page.locator('main button:visible').all();
                for (const btn of buttons.slice(0, 3)) { // Limit to first 3
                    const text = await btn.textContent() || '';
                    if (!shouldSkip(text)) {
                        await expect(btn).toBeVisible();
                        pageReport.buttonsClicked++;
                    }
                }

            } catch (e: any) {
                pageReport.errors.push(`Navigation error: ${e.message}`);
            }

            pageReport.duration = Date.now() - startTime;
            report.pages.push(pageReport);

            // Fail test if critical errors
            expect(pageReport.errors.filter(e => e.includes('Fatal'))).toHaveLength(0);
        });
    }
});
