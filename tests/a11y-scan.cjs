// Accessibility baseline: runs axe-core against the live app on the NC34
// container and reports violations by impact. Network-gated; not in the offline
// suite. Usage: node tests/a11y-scan.cjs
const puppeteer = require('puppeteer');
const fs = require('fs');
const axeSrc = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const BASE = 'http://localhost:8099';

async function scan(page, label) {
	await page.evaluate(axeSrc);
	const res = await page.evaluate(async () => {
		return await window.axe.run(document.querySelector('#lantern') || document.body, {
			resultTypes: ['violations'],
			runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
		});
	});
	const byImpact = { critical: [], serious: [], moderate: [], minor: [] };
	for (const v of res.violations) (byImpact[v.impact] || (byImpact[v.impact] = [])).push(v);
	console.log(`\n=== ${label} ===`);
	for (const imp of ['critical', 'serious', 'moderate', 'minor']) {
		for (const v of byImpact[imp] || []) {
			console.log(`  [${imp}] ${v.id}: ${v.help} (${v.nodes.length} node(s))`);
			console.log(`      ${v.nodes[0].target.join(' ')}`);
		}
	}
	const crit = (byImpact.critical || []).length + (byImpact.serious || []).length;
	console.log(`  -> ${res.violations.length} violation type(s); ${crit} critical/serious`);
	return crit;
}

(async () => {
	const b = await puppeteer.launch({ headless: 'shell', args: ['--no-sandbox'] });
	const p = await b.newPage();
	await p.goto(BASE + '/login', { waitUntil: 'networkidle2' });
	await p.type('input[name=user]', 'admin');
	await p.type('input[name=password]', 'admin_pass_123');
	await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle2' }), p.click('button[type=submit]')]);

	let crit = 0;
	await p.goto(BASE + '/apps/lantern/?repo=lfsdemo', { waitUntil: 'networkidle2' });
	await p.waitForSelector('#lantern', { timeout: 15000 });
	await new Promise((r) => setTimeout(r, 2500));
	crit += await scan(p, 'tree / repo view');

	await p.goto(BASE + '/apps/lantern/?repo=lfsdemo&blob=app.js', { waitUntil: 'networkidle2' });
	await p.waitForSelector('#lantern', { timeout: 15000 });
	await new Promise((r) => setTimeout(r, 2500));
	crit += await scan(p, 'blob / file view');

	await b.close();
	console.log(`\nTOTAL critical/serious across views: ${crit}`);
	process.exit(0);
})().catch((e) => { console.error('SCAN ERROR:', e.message); process.exit(2); });
