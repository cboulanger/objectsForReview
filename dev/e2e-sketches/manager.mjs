import { chromium } from 'playwright-core';

const B = 'http://localhost:8080/index.php/test/en';
const OUT = new URL('.', import.meta.url).pathname;
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
page.on('pageerror', e => console.log('PAGE ERROR:', e.message));
page.on('console', m => { if (m.type() === 'error') console.log('CONSOLE:', m.text()); });

await page.goto(`${B}/login`);
await page.fill('input[name=username]', 'admin');
await page.fill('input[name=password]', 'ojsadmin');
await Promise.all([page.waitForNavigation(), page.click('form#login button[type=submit], form#login .submit')]);

await page.goto(`${B}/management/settings/website`);
await page.getByRole('tab', { name: /objects for review/i }).click();
const add = page.locator('a[id*="addAvailableObjectForReview"]');
await add.waitFor();
await add.click();

const form = page.locator('#availableObjectsForReviewForm');
await form.waitFor();
const box = form.locator('.orDoiLookup');
console.log('identifierType default:', await form.locator('[name=identifierType]').inputValue());
console.log('button visible (doi):', await box.isVisible());

await form.locator('[name=identifier]').fill('https://doi.org/10.1007/978-3-319-25226-1');
await box.locator('button').click();
await box.locator('.orDoiLookupStatus').filter({ hasText: /filled in|failed|not found|valid/ }).waitFor({ timeout: 20000 });
console.log('status:', await box.locator('.orDoiLookupStatus').textContent());

const v = async n => form.locator(`[name=${n}]`).inputValue();
console.log({
	identifier: await v('identifier'),
	resourceType: await v('resourceType'),
	authors: await v('authors'),
	publisher: await v('publisher'),
	year: await v('year'),
	titleEditor: await page.evaluate(() => {
		const ta = document.querySelector('#availableObjectsForReviewForm [name=title]');
		const ed = window.tinymce && tinymce.get(ta.id);
		return ed ? ed.getContent() : '(no tinymce) ' + ta.value;
	}),
});
await page.screenshot({ path: OUT + 'manager-filled.png' });

// Hidden for non-DOI identifier types
await form.locator('[name=identifierType]').selectOption('ISBN');
console.log('button visible (isbn):', await box.isVisible());
await form.locator('[name=identifierType]').selectOption('DOI');

// Save and check the grid
await form.locator('button.submitFormButton').click();
await form.waitFor({ state: 'detached', timeout: 15000 });
const row = page.locator('tr', { hasText: '10.1007/978-3-319-25226-1' });
await row.first().waitFor({ timeout: 15000 });
console.log('grid row:', (await row.first().innerText()).replace(/\s+/g, ' '));
await page.screenshot({ path: OUT + 'manager-saved.png' });

await browser.close();
