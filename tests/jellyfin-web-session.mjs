// CI only: proves that the session the portal hands over (docker/video-proxy/public/enter.js) signs
// Jellyfin's real web interface in, and that the gateway adds the Multisuite look and return link.
// Usage: node tests/jellyfin-web-session.mjs <gateway> <server id> <user id> <token> <screens dir>
import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [gateway, server, user, token, screens] = process.argv.slice(2);
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const chrome = spawn('google-chrome', ['--headless=new', '--no-sandbox', '--disable-gpu', '--remote-debugging-port=9333',
  `--user-data-dir=${mkdtempSync(join(tmpdir(), 'jf-'))}`, '--window-size=1280,800', 'about:blank'], { stdio: 'ignore' });

let page;
for (let attempt = 0; attempt < 50 && !page; attempt++) {
  await sleep(200);
  try { page = (await (await fetch('http://127.0.0.1:9333/json/list')).json()).find(target => target.type === 'page'); } catch { /* starting */ }
}
if (!page) throw new Error('Chrome did not start');
const socket = new WebSocket(page.webSocketDebuggerUrl);
await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
let id = 0;
const pending = new Map();
socket.addEventListener('message', ({ data }) => {
  const message = JSON.parse(data);
  if (pending.has(message.id)) { pending.get(message.id)(message); pending.delete(message.id); }
});
const send = (method, params = {}) => new Promise(resolve => { pending.set(++id, resolve); socket.send(JSON.stringify({ id, method, params })); });
const evaluate = async expression => (await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result?.result?.value;

let failed = null;
try {
  await send('Page.enable');
  await send('Page.navigate', { url: `${gateway}/web/` });
  await sleep(4000);
  // Same page shape the portal serves (resources/views/video-enter.blade.php), on the gateway origin.
  const session = JSON.stringify({ server, name: 'CI', user, token });
  await evaluate(`document.body.dataset.session = ${JSON.stringify(session)};
    const script = document.createElement('script'); script.src = '/_multisuite/enter.js'; document.body.append(script); true`);
  let state = null;
  for (let attempt = 0; attempt < 40; attempt++) {
    await sleep(1000);
    state = await evaluate(`({ hash: location.hash, user: !!document.querySelector('.headerUserButton, .headerUserButtonRound'),
      login: !!document.querySelector('#loginPage:not(.hide), .manualLoginForm:not(.hide)'),
      back: !!document.querySelector('.skinHeader .headerLeft .ms-video-return'),
      look: getComputedStyle(document.documentElement).getPropertyValue('--ms-accent').trim(),
      stored: !!JSON.parse(localStorage.getItem('jellyfin_credentials') || '{}').Servers?.some(s => s.AccessToken) })`);
    if (state?.user && state.back) break;
  }
  console.log(JSON.stringify(state));
  const shot = await send('Page.captureScreenshot', { format: 'png' });
  if (shot.result?.data) writeFileSync(join(screens, 'home-1280.png'), Buffer.from(shot.result.data, 'base64'));
  if (!state?.stored) failed = 'enter.js did not store the session';
  else if (!state.user || state.login) failed = 'Jellyfin did not resume the session';
  else if (!state.back) failed = 'return link missing in the header';
  else if (state.look !== '#dbf368') failed = 'Multisuite look not applied';
} finally {
  socket.close();
  chrome.kill();
}
if (failed) { console.error(failed); process.exit(1); }
