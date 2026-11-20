// Run only against the disposable seeded portal on loopback.
const fs = require('node:fs');
const puppeteer = require('puppeteer-core');
const base = 'http://127.0.0.1:8000';
const out = 'design-results';
async function measure() {
    const {default: lighthouse} = await import('lighthouse');
    const browser = await puppeteer.launch({executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox']});
    fs.mkdirSync(out, {recursive: true});
    const rows = [];
    const critical = [];
    try {
        const page = await browser.newPage();
        for (const [slug, route] of Object.entries({login:'/login', dashboard:'/dashboard', files:'/files', preferences:'/settings/preferences'})) {
            if (slug === 'dashboard') {
                await page.type('input[name=email]', 'test@example.com');
                await page.type('input[name=password]', 'password');
                await Promise.all([page.waitForNavigation(), page.click('[data-test=login-button]')]);
            }
            await page.goto(base + route, {waitUntil: 'networkidle2'});
            if (new URL(page.url()).pathname !== route) throw Error('Unexpected redirect: ' + page.url());
            for (const width of [360, 768, 1280]) {
                await page.setViewport({width, height: 860});
                for (const scheme of ['light','dark']) {
                    await page.emulateMediaFeatures([{name:'prefers-color-scheme',value:scheme},{name:'prefers-reduced-motion',value:'reduce'}]);
                    if (width === 1280) {
                        await page.addScriptTag({path:require.resolve('axe-core/axe.min.js')});
                        const findings = await page.evaluate(async () => (await axe.run()).violations.map(({id,impact,nodes})=>({id,impact,count:nodes.length})));
                        fs.writeFileSync(out+'/'+slug+'-'+scheme+'-axe.json', JSON.stringify(findings,null,2));
                        critical.push(...findings.filter(v=>v.impact==='critical'));
                    }
                    await page.screenshot({path: out + '/' + [slug,width,scheme].join('-') + '.png'});
                }
            }
            if (slug === 'files') {
                const actions = {};
                let updates = 0;
                const track = req => { if (req.method() === 'POST' && req.url().includes('livewire')) updates++; };
                page.on('request', track);
                for (const [name, action] of [
                    ['search', () => page.type('[name=q]', 'prueba')],
                    ['kind', () => page.select('[name=tipo]', 'image')],
                    ['order', () => page.click('[wire\\:click="toggleDirection"]')],
                ]) {
                    updates = 0;
                    await action();
                    await page.waitForNetworkIdle({idleTime:800});
                    actions[name] = updates;
                }
                page.off('request', track);
                console.log('Network interactions', actions);
                fs.writeFileSync(out+'/interactions.json',JSON.stringify(actions));
                await page.goto(base+route,{waitUntil:'networkidle2'});
            }
            for (const formFactor of ['mobile','desktop']) {
                for (let run=1;run<=3;run++) {
                    const cdp=await page.createCDPSession();
                    await cdp.send('Network.clearBrowserCache');
                    await cdp.detach();
                    const desktop = formFactor === 'desktop';
                    const config = desktop ? (await import('lighthouse/core/config/desktop-config.js')).default : undefined;
                    const {lhr} = await lighthouse(base+route,{port:Number(new URL(browser.wsEndpoint()).port),disableStorageReset:true,onlyCategories:['performance','accessibility','best-practices']},config);
                    if(lhr.runtimeError || new URL(lhr.finalDisplayedUrl || lhr.finalUrl).pathname!==route) throw Error('Invalid Lighthouse navigation '+route);
                    const row={slug,formFactor,run,scores:Object.fromEntries(Object.entries(lhr.categories).map(([k,v])=>[k,v.score])),requests:lhr.audits['network-requests'].details.items.length,lcp:lhr.audits['largest-contentful-paint'].numericValue,cls:lhr.audits['cumulative-layout-shift'].numericValue};
                    rows.push(row);
                    console.log(JSON.stringify(row));
                    fs.writeFileSync(out+'/metrics.json',JSON.stringify(rows,null,2));
                }
            }
        }
        if (critical.length && process.env.DESIGN_REVISION === 'candidate') throw Error('Critical axe findings: '+JSON.stringify(critical));
    } finally { await browser.close(); }
}
measure().catch(error=>{console.error(error);process.exitCode=1;});
