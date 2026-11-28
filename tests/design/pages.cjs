// UI check of portal pages in a real browser (disposable seeded portal on loopback, see
// .github/workflows/ui-check.yml): no serious accessibility findings (axe), no horizontal
// overflow on a phone, no browser errors, in light and dark. Prints a summary per page.
const puppeteer = require('puppeteer-core');
const base = 'http://127.0.0.1:8000';
const pages = (process.env.UI_PAGES || '/dashboard').split(',');

(async () => {
    const browser = await puppeteer.launch({executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox']});
    const failures = [];
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (message.type() === 'error' && !/net::ERR_FAILED|Failed to load resource/.test(message.text())) errors.push(message.text()); });
        // Service gateways are not running here: keep the portal page instead of leaving it.
        await page.setRequestInterception(true);
        page.on('request', request => new URL(request.url()).origin === base ? request.continue() : request.abort());

        await page.goto(base + '/login', {waitUntil: 'networkidle2'});
        await page.type('input[name=email]', 'test@example.com');
        await page.type('input[name=password]', 'password');
        await Promise.all([page.waitForNavigation(), page.click('[data-test=login-button]')]);

        for (const route of pages) {
            await page.goto(base + route, {waitUntil: 'networkidle2'}).catch(() => {});
            const path = new URL(page.url()).pathname;
            if (path !== route) failures.push(route + ': redirected to ' + path);
            for (const width of [360, 1280]) {
                await page.setViewport({width, height: 860});
                for (const scheme of ['light', 'dark']) {
                    errors.length = 0;
                    await page.emulateMediaFeatures([{name: 'prefers-color-scheme', value: scheme}, {name: 'prefers-reduced-motion', value: 'reduce'}]);
                    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                    const overflow = await page.evaluate(() => {
                        const wide = [...document.querySelectorAll('body *')].filter(el => el.getBoundingClientRect().right > innerWidth + 1 && getComputedStyle(el).position !== 'fixed' && !el.closest('[aria-hidden=true], .ui-table-scroll, table'));
                        return {page: document.documentElement.scrollWidth > innerWidth + 1, examples: wide.slice(0, 3).map(el => el.tagName.toLowerCase() + '.' + [...el.classList].join('.'))};
                    });
                    await page.addScriptTag({path: require.resolve('axe-core/axe.min.js')});
                    const findings = await page.evaluate(async () => (await axe.run()).violations
                        .filter(v => v.impact === 'critical' || v.impact === 'serious' || v.id === 'color-contrast')
                        .map(v => v.id + ' (' + v.impact + '): ' + v.nodes.slice(0, 2).map(n => n.target.join(' ')).join(' | ')));
                    const label = route + ' ' + width + 'px ' + scheme;
                    console.log(label + ': axe ' + findings.length + ', overflow ' + (overflow.page ? 'YES ' + overflow.examples.join(', ') : 'no') + ', errors ' + errors.length);
                    findings.forEach(f => failures.push(label + ': ' + f));
                    if (overflow.page) failures.push(label + ': horizontal overflow ' + overflow.examples.join(', '));
                    errors.forEach(e => failures.push(label + ': browser error ' + e));
                }
            }
        }
    } finally {
        await browser.close();
    }
    if (failures.length) {
        console.error('UI check failed:\n' + failures.join('\n'));
        process.exit(1);
    }
    console.log('PASS UI check: ' + pages.join(', '));
})().catch(error => { console.error(error); process.exit(1); });
