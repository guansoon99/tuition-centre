// Crop just the announcement card on the student home page.
const puppeteer = require('puppeteer-core');
const path = require('path');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = 'http://127.0.0.1:8000';
const OUT = path.join(__dirname, 'images');

(async () => {
    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: 'new',
        defaultViewport: { width: 1280, height: 800, deviceScaleFactor: 2 },
    });

    const p = await browser.newPage();
    await p.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    await p.type('input[name=username]', 'student');
    await p.type('input[name=password]', 'qwe123');
    await Promise.all([
        p.click('button[type=submit]'),
        p.waitForNavigation({ waitUntil: 'networkidle0' }),
    ]);
    await new Promise(r => setTimeout(r, 500));

    const card = await p.$('main article');
    if (card) {
        await card.screenshot({ path: path.join(OUT, '04-announcement.png') });
        console.log('04-announcement.png  OK');
    } else {
        console.log('no announcement card found');
    }

    await browser.close();
})();
