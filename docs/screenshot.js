// Automated full-page screenshotting for the User Manual.
// Signs in as admin (username `admin`, password `qwe123`), then walks
// through the target pages capturing each into docs/images/.
//
// Prereq: `php artisan serve` running on http://127.0.0.1:8000
// Run:    node docs/screenshot.js

const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = 'http://127.0.0.1:8000';
const OUT = path.join(__dirname, 'images');

const SHOTS = [
    { file: '02-layout.png',            path: '/',                   waitFor: 'body' },
    { file: '03-student-home.png',      path: '/',                   waitFor: 'body' },
    { file: '18-users-list.png',        path: '/users',              waitFor: 'h1' },
    { file: '19-user-create.png',       path: '/users/create',       waitFor: 'form' },
    { file: '20-users-filter.png',      path: '/users',              waitFor: 'input[name=q]' },
    { file: '21-import-preview.png',    path: '/import-students',    waitFor: 'h1' },
    { file: '23-roles.png',             path: '/roles',              waitFor: 'h1' },
    { file: '24-role-edit.png',         path: '/roles/create',       waitFor: 'form' },
    { file: '25-courses-list.png',      path: '/courses',            waitFor: 'h1' },
    { file: '26-banner.png',            path: '/banner',             waitFor: 'h1' },
    { file: '27-announcement-send.png', path: '/announcements/create', waitFor: 'form' },
    { file: '28-settings.png',          path: '/settings',           waitFor: 'form' },
];

(async () => {
    if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: 'new',
        defaultViewport: { width: 1280, height: 800, deviceScaleFactor: 2 },
    });

    const page = await browser.newPage();

    console.log('Signing in as admin…');
    await page.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    await page.type('input[name=username]', 'admin');
    await page.type('input[name=password]', 'qwe123');
    await Promise.all([
        page.click('button[type=submit]'),
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
    ]);

    for (const shot of SHOTS) {
        const url = BASE + shot.path;
        process.stdout.write(`  ${shot.file.padEnd(28)}  ${shot.path}  `);
        try {
            await page.goto(url, { waitUntil: 'networkidle0', timeout: 20000 });
            if (shot.waitFor) {
                await page.waitForSelector(shot.waitFor, { timeout: 5000 }).catch(() => {});
            }
            // Give any client-side widgets (Alpine, Flatpickr) a beat to hydrate.
            await new Promise(r => setTimeout(r, 400));
            await page.screenshot({
                path: path.join(OUT, shot.file),
                fullPage: true,
                type: 'png',
            });
            console.log('OK');
        } catch (e) {
            console.log('FAIL:', e.message);
        }
    }

    await browser.close();
    console.log('\nDone. Files in docs/images/');
})();
