// Captures the remaining screenshots the first run couldn't do without
// context (login page while signed-out, course pages, resource-form
// variants, hover states, etc.). Requires the same admin creds.

const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = 'http://127.0.0.1:8000';
const OUT = path.join(__dirname, 'images');
const COURSE_SLUG = 'pengajian-am-sem-1-kelas-a';
const SECTION_ID = 1;

(async () => {
    if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: 'new',
        defaultViewport: { width: 1280, height: 800, deviceScaleFactor: 2 },
    });

    // === 01 login page: brand-new context so we're signed out ===
    {
        const ctx = await browser.createBrowserContext();
        const p = await ctx.newPage();
        await p.setViewport({ width: 1280, height: 800, deviceScaleFactor: 2 });
        await p.goto(BASE + '/login', { waitUntil: 'networkidle0' });
        await new Promise(r => setTimeout(r, 300));
        await p.screenshot({ path: path.join(OUT, '01-login.png'), fullPage: true });
        await ctx.close();
        console.log('01-login.png  OK');
    }

    // === Sign in as admin for the rest ===
    const page = await browser.newPage();
    await page.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    await page.type('input[name=username]', 'admin');
    await page.type('input[name=password]', 'qwe123');
    await Promise.all([
        page.click('button[type=submit]'),
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
    ]);

    const shot = async (file, url, before) => {
        try {
            await page.goto(BASE + url, { waitUntil: 'networkidle0', timeout: 20000 });
            await new Promise(r => setTimeout(r, 500));
            if (before) await before(page);
            await new Promise(r => setTimeout(r, 300));
            await page.screenshot({ path: path.join(OUT, file), fullPage: true });
            console.log(file + '  OK');
        } catch (e) {
            console.log(file + '  FAIL: ' + e.message);
        }
    };

    // === 05 course page as-viewed ===
    await shot('05-course-page.png', '/courses/' + COURSE_SLUG);

    // === 08/09/11 course edit + sections tab ===
    await shot('08-course-tabs.png', '/courses/' + COURSE_SLUG + '/edit?tab=sections');
    await shot('09-insert-section.png', '/courses/' + COURSE_SLUG + '/edit?tab=sections');
    await shot('11-add-resource.png', '/courses/' + COURSE_SLUG + '/edit?tab=sections');

    // === 10 section edit modal open ===
    await shot('10-section-edit.png', '/courses/' + COURSE_SLUG + '/edit?tab=sections', async (p) => {
        // Click the first section's Edit button to open its modal.
        await p.evaluate(() => {
            const btns = [...document.querySelectorAll('article button')].filter(b => b.textContent.trim() === 'Edit');
            if (btns[0]) btns[0].click();
        });
        await new Promise(r => setTimeout(r, 600));
    });

    // === 12–16 resource form variants ===
    const resourceUrl = '/sections/' + SECTION_ID + '/materials/create';
    const setType = (v) => async (p) => {
        await p.select('select[name=type]', v);
        await new Promise(r => setTimeout(r, 300));
    };
    await shot('12-resource-pdf.png',       resourceUrl, setType('pdf'));
    await shot('13-resource-link.png',      resourceUrl, setType('external_link'));
    await shot('14-resource-video.png',     resourceUrl, setType('video_link'));
    await shot('15-resource-text.png',      resourceUrl, setType('text'));
    await shot('16-resource-countdown.png', resourceUrl, setType('countdown'));

    // === 17 pencil hover — hover the first material row ===
    await shot('17-resource-pencil.png', '/courses/' + COURSE_SLUG + '/edit?tab=sections', async (p) => {
        const target = await p.$('article .divide-y > *');
        if (target) await target.hover();
    });

    // === 22 import result — we can only show the placeholder state without actually running an import ===
    await shot('22-import-result.png', '/import-students');

    // === 04 announcement card — student home. Sign out then use a student account seeded with announcements ===
    // If no announcement exists in the DB, this will just show the empty home. Skip if student data missing.
    // For now grab the admin home which shows their own copy.
    await shot('04-announcement.png', '/');

    // === 06 PDF viewer — if a PDF material exists ===
    const firstPdf = await page.evaluate(async (base) => {
        const r = await fetch(base + '/courses/' + '${COURSE_SLUG}', { credentials: 'include' });
        return r.status;
    }, BASE);
    // We can't easily find a PDF URL without introspecting the DB; skip if the seeded data has none.
    // The manual's placeholder text still guides the user.

    // === 07 teacher sidebar — sign in as teacher in a new context ===
    {
        const ctx = await browser.createBrowserContext();
        const p = await ctx.newPage();
        await p.setViewport({ width: 1280, height: 800, deviceScaleFactor: 2 });
        await p.goto(BASE + '/login', { waitUntil: 'networkidle0' });
        await p.type('input[name=username]', 'cikgu_aini');
        await p.type('input[name=password]', 'password');
        try {
            await Promise.all([
                p.click('button[type=submit]'),
                p.waitForNavigation({ waitUntil: 'networkidle0', timeout: 10000 }),
            ]);
            await new Promise(r => setTimeout(r, 400));
            await p.screenshot({ path: path.join(OUT, '07-teacher-sidebar.png'), fullPage: true });
            console.log('07-teacher-sidebar.png  OK');
        } catch (e) {
            console.log('07-teacher-sidebar.png  FAIL: ' + e.message);
        }
        await ctx.close();
    }

    await browser.close();
    console.log('\nDone.');
})();
