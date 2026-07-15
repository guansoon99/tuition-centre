// Final missing shot: the PDF viewer page.
const puppeteer = require('puppeteer-core');
const path = require('path');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = 'http://127.0.0.1:8000';
const OUT = path.join(__dirname, 'images');
const PDF_ID = 1;

(async () => {
    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: 'new',
        defaultViewport: { width: 1280, height: 800, deviceScaleFactor: 2 },
    });
    const page = await browser.newPage();

    await page.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    await page.type('input[name=username]', 'admin');
    await page.type('input[name=password]', 'qwe123');
    await Promise.all([
        page.click('button[type=submit]'),
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
    ]);

    try {
        await page.goto(BASE + '/materials/' + PDF_ID, { waitUntil: 'networkidle0', timeout: 15000 });
        await new Promise(r => setTimeout(r, 1500));
        await page.screenshot({ path: path.join(OUT, '06-pdf-viewer.png'), fullPage: true });
        console.log('06-pdf-viewer.png  OK');
    } catch (e) {
        console.log('06-pdf-viewer.png  FAIL: ' + e.message);
    }

    await browser.close();
})();
