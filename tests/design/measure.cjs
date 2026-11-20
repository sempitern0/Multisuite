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
                    await page.screenshot({path: out + '/' + [slug,width,scheme].join('-') + '.png'});
                }
            }
            await page.addScriptTag({path:require.resolve('axe-core/axe.min.js')});
            const axe = await page.evaluate(async () => (await axe.run()).violations.map(({id,impact,nodes})=>({id,impact,count:nodes.length})));
            fs.writeFileSync(out+'/'+slug+'-axe.json', JSON.stringify(axe,null,2));
            for (const formFactor of ['mobile','desktop']) {
                for (let run=1;run<=3;run++) {
                    const cdp=await page.createCDPSession();
                    await cdp.send('Network.clearBrowserCache');
                    await cdp.detach();
                    const desktop = formFactor === 'desktop';
                    const {lhr} = await lighthouse(base+route,{port:Number(new URL(browser.wsEndpoint()).port),disableStorageReset:true,onlyCategories:['performance','accessibility','best-practices'],...(desktop?{preset:'desktop'}:{})});
                    if(lhr.runtimeError || new URL(lhr.finalDisplayedUrl || lhr.finalUrl).pathname!==route) throw Error('Invalid Lighthouse navigation '+route);
                    const row={slug,formFactor,run,scores:Object.fromEntries(Object.entries(lhr.categories).map(([k,v])=>[k,v.score])),requests:lhr.audits['network-requests'].details.items.length,lcp:lhr.audits['largest-contentful-paint'].numericValue,cls:lhr.audits['cumulative-layout-shift'].numericValue};
                    rows.push(row);
                    fs.writeFileSync(out+'/metrics.json',JSON.stringify(rows,null,2));
                }
            }
        }
    } finally { await browser.close(); }
}
measure().catch(error=>{console.error(error);process.exitCode=1;});
