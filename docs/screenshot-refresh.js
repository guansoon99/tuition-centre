// Retakes just the shots that need updating: main screen + home + student home + course page + course tabs.
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = 'http://127.0.0.1:8000';
const OUT = path.join(__dirname, 'images');
const COURSE_SLUG = 'pengajian-am-sem-1-kelas-a';

(async () => {
    if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: 'new',
        defaultViewport: { width: 1280, height: 800, deviceScaleFactor: 2 },
    });

    // Admin session for the layout / main-screen / dashboard shots.
    const admin = await browser.newPage();
    await admin.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    await admin.type('input[name=username]', 'admin');
    await admin.type('input[name=password]', 'qwe123');
    await Promise.all([
        admin.click('button[type=submit]'),
        admin.waitForNavigation({ waitUntil: 'networkidle0' }),
    ]);

    const grab = async (page, file, url) => {
        try {
            await page.goto(BASE + url, { waitUntil: 'networkidle0', timeout: 20000 });
            await new Promise(r => setTimeout(r, 500));
            await page.screenshot({ path: path.join(OUT, file), fullPage: true });
            console.log(file + '  OK');
        } catch (e) {
            console.log(file + '  FAIL: ' + e.message);
        }
    };

    // Main screen / admin home
    await grab(admin, '02-layout.png', '/');

    // A course page (admin view — same layout students see, plus a manage banner if any)
    await grab(admin, '05-course-page.png', '/courses/' + COURSE_SLUG);

    // Course tabs (edit page)
    await grab(admin, '08-course-tabs.png', '/courses/' + COURSE_SLUG + '/edit?tab=sections');

    // Student home — separate browser context so we're not still signed in as admin.
    const studentCtx = await browser.createBrowserContext();
    const student = await studentCtx.newPage();
    await student.setViewport({ width: 1280, height: 800, deviceScaleFactor: 2 });
    await student.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    await student.type('input[name=username]', 'student');
    await student.type('input[name=password]', 'qwe123');
    try {
        await Promise.all([
            student.click('button[type=submit]'),
            student.waitForNavigation({ waitUntil: 'networkidle0', timeout: 10000 }),
        ]);
    } catch (e) {
        console.log('student login: ' + e.message);
    }
    await grab(student, '03-student-home.png', '/');
    await studentCtx.close();

    await browser.close();
    console.log('\nDone.');
})();
