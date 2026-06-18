// Headless-browser live verification of the §3 hardening (LFS + submodule)
// against the throwaway NC34 container. Not part of the offline suite.
const puppeteer = require('puppeteer');

const BASE = 'http://localhost:8099';
const USER = 'admin';
const PASS = 'admin_pass_123';

(async () => {
	const browser = await puppeteer.launch({
		headless: 'shell',
		args: ['--no-sandbox', '--disable-setuid-sandbox'],
	});
	const page = await browser.newPage();
	const consoleErrors = [];
	const badResponses = [];
	page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
	page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));
	page.on('response', (r) => {
		const u = r.url();
		if (u.includes('/apps/lantern') && r.status() >= 400) badResponses.push(r.status() + ' ' + u);
	});

	const results = [];
	const assert = (label, cond) => results.push([cond ? 'PASS' : 'FAIL', label]);

	// --- login ---
	await page.goto(BASE + '/login', { waitUntil: 'networkidle2' });
	await page.type('input[name=user]', USER);
	await page.type('input[name=password]', PASS);
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'networkidle2' }),
		page.click('button[type=submit], input[type=submit]'),
	]);

	// --- open the app at the fixture repo ---
	await page.goto(BASE + '/apps/lantern/?repo=lfsdemo', { waitUntil: 'networkidle2' });
	await page.waitForSelector('#lantern', { timeout: 15000 });
	await new Promise((r) => setTimeout(r, 2500)); // let the tree fetch settle

	const appText = await page.$eval('#lantern', (el) => el.innerText);
	assert('app mounted with #lantern content', appText.length > 0);

	// Submodule: the tree should show vendored-lib labelled as a submodule, disabled.
	const treeInfo = await page.evaluate(() => {
		const rows = Array.from(document.querySelectorAll('.lantern-tree-row'));
		const sub = rows.find((r) => r.textContent.includes('vendored-lib'));
		return {
			found: !!sub,
			text: sub ? sub.textContent.replace(/\s+/g, ' ').trim() : '',
			disabled: sub ? sub.disabled === true || sub.hasAttribute('disabled') : false,
			hasLink: sub ? sub.textContent.includes('🔗') : false,
		};
	});
	assert('submodule entry present in tree', treeInfo.found);
	assert('submodule shown as a submodule (link icon + "submodule")',
		treeInfo.hasLink && /submodule/i.test(treeInfo.text));
	assert('submodule row is not clickable (disabled)', treeInfo.disabled);

	// LFS blob: open big.png (an LFS pointer with an image extension).
	await page.goto(BASE + '/apps/lantern/?repo=lfsdemo&blob=big.png', { waitUntil: 'networkidle2' });
	await page.waitForSelector('#lantern', { timeout: 15000 });
	await new Promise((r) => setTimeout(r, 2500));
	const blobInfo = await page.evaluate(() => {
		const root = document.querySelector('#lantern');
		const text = root ? root.innerText : '';
		const img = root ? root.querySelector('.lantern-image') : null;
		return {
			hasLfsNotice: /Stored with Git LFS/i.test(text),
			showsSize: /1048576/.test(text),
			rendersImg: !!img,
			leaksPointer: /git-lfs\.github\.com\/spec\/v1/.test(text),
		};
	});
	assert('LFS blob shows "Stored with Git LFS" notice', blobInfo.hasLfsNotice);
	assert('LFS notice shows the declared size', blobInfo.showsSize);
	assert('LFS-backed image is NOT rendered as a broken <img>', !blobInfo.rendersImg);
	assert('raw LFS pointer text is NOT leaked into the view', !blobInfo.leaksPointer);

	// A real (non-LFS) image must still render as an <img> with no raw 412.
	await page.goto(BASE + '/apps/lantern/?repo=lfsdemo&blob=real.png', { waitUntil: 'networkidle2' });
	await page.waitForSelector('#lantern', { timeout: 15000 });
	await new Promise((r) => setTimeout(r, 2500));
	const realImg = await page.evaluate(() => {
		const root = document.querySelector('#lantern');
		return {
			rendersImg: !!(root && root.querySelector('.lantern-image')),
			hasLfsNotice: /Stored with Git LFS/i.test(root ? root.innerText : ''),
		};
	});
	assert('a real (non-LFS) image still renders as <img>', realImg.rendersImg);
	assert('a real image is NOT mislabelled as LFS', !realImg.hasLfsNotice);

	// Open the normal text file to confirm regular rendering still works.
	await page.goto(BASE + '/apps/lantern/?repo=lfsdemo&blob=app.js', { waitUntil: 'networkidle2' });
	await page.waitForSelector('#lantern', { timeout: 15000 });
	await new Promise((r) => setTimeout(r, 2000));
	const normalText = await page.$eval('#lantern', (el) => el.innerText);
	assert('normal file renders its content', normalText.includes('console.log'));

	assert('zero console errors', consoleErrors.length === 0);
	assert('zero /apps/lantern 4xx/5xx responses', badResponses.length === 0);

	await browser.close();

	let failed = 0;
	for (const [s, l] of results) { console.log(`  ${s}  ${l}`); if (s === 'FAIL') failed++; }
	if (consoleErrors.length) console.log('  console errors:\n   ' + consoleErrors.join('\n   '));
	if (badResponses.length) console.log('  bad responses:\n   ' + badResponses.join('\n   '));
	console.log(`\nRESULT: ${results.length - failed} passed, ${failed} failed`);
	process.exit(failed === 0 ? 0 : 1);
})().catch((e) => { console.error('VERIFY ERROR:', e.message); process.exit(2); });
