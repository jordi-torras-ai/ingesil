import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';

const baseUrl = process.env.APP_BASE_URL ?? 'http://127.0.0.1:8000';
const outputDir = path.resolve('output/playwright/smart-search');
const email = process.env.MANUAL_LOGIN_EMAIL ?? 'jtorras@guanta.ai';
const password = process.env.MANUAL_LOGIN_PASSWORD ?? 'T_xefu_laye_popo_9471';
const query = process.env.SMART_SEARCH_QUERY ?? 'What environmental or waste-related notices were published on March 5, 2026?';

await mkdir(outputDir, { recursive: true });

const browser = await chromium.launch({
  channel: 'chrome',
  headless: true,
});

const context = await browser.newContext({
  viewport: { width: 1440, height: 1100 },
  colorScheme: 'dark',
});

const page = await context.newPage();

async function screenshot(name) {
  await page.screenshot({
    path: path.join(outputDir, name),
    fullPage: true,
  });
}

try {
  await page.goto(`${baseUrl}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel(/email/i).fill(email);
  await page.getByLabel(/password/i).fill(password);
  await screenshot('01-login.png');

  await page.getByRole('button', { name: /log in|sign in/i }).click();
  await Promise.race([
    page.waitForURL(/\/admin(\/)?$/, { timeout: 30000 }),
    page.getByRole('link', { name: /smart search/i }).waitFor({ timeout: 30000 }),
  ]);

  await screenshot('02-dashboard.png');

  await page.getByRole('link', { name: /smart search/i }).click();
  await page.waitForURL(/\/admin\/smart-search/);
  await page.getByLabel(/question/i).waitFor({ timeout: 30000 });
  await screenshot('03-smart-search-empty.png');

  await page.getByLabel(/question/i).fill(query);
  await screenshot('04-smart-search-query.png');

  await page.getByRole('button', { name: /^search$/i }).click();

  await page.getByText(/document\(s\)|documents?/i).waitFor({ timeout: 120000 });
  await page.getByText(/answer/i).waitFor({ timeout: 120000 });
  await page.getByText(/results/i).waitFor({ timeout: 120000 });

  await page.waitForTimeout(3000);
  await screenshot('05-smart-search-results.png');

  await page.waitForFunction(() => !document.body.innerText.includes('Thinking...'), { timeout: 180000 });
  await page.waitForTimeout(1000);
  await screenshot('06-smart-search-answer.png');
} finally {
  await context.close();
  await browser.close();
}
