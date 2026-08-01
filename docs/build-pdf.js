// Regenerate USER_MANUAL.pdf from USER_MANUAL.md.
// Run:  cd docs && node build-pdf.js

const puppeteer = require('puppeteer-core');
const { marked } = require('marked');
const fs = require('fs');
const path = require('path');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MD_PATH   = path.join(__dirname, 'USER_MANUAL.md');
const PDF_PATH  = path.join(__dirname, 'USER_MANUAL.pdf');
const TEMP_HTML = path.join(__dirname, '.build.html');

const md = fs.readFileSync(MD_PATH, 'utf8');
const bodyHtml = marked.parse(md);

const html = `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>User Guide</title>
<style>
  html { -webkit-print-color-adjust: exact; }
  body {
    font: 14px/1.55 -apple-system, "Segoe UI", Roboto, sans-serif;
    color: #0f172a;
    max-width: 780px;
    margin: 0 auto;
    padding: 0 24px;
  }
  h1 { font-size: 26px; margin: 24px 0 12px; }
  h2 { font-size: 22px; margin: 28px 0 12px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
  h3 { font-size: 17px; margin: 20px 0 8px; }
  p, li { color: #334155; }
  code { background: #f1f5f9; padding: 1px 5px; border-radius: 3px; font-size: 12.5px; }
  pre { background: #f1f5f9; padding: 12px; border-radius: 6px; overflow-x: auto; }
  hr { border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0; }
  img { max-width: 100%; height: auto; border-radius: 4px; box-shadow: 0 0 0 1px #e2e8f0; }
  a { color: #0369a1; text-decoration: none; }
  blockquote { border-left: 3px solid #cbd5e1; margin: 12px 0; padding: 4px 12px; color: #475569; }
  table { border-collapse: collapse; width: 100%; margin: 12px 0; }
  th, td { border: 1px solid #cbd5e1; padding: 6px 10px; text-align: left; }
  th { background: #f1f5f9; }
</style>
</head>
<body>
${bodyHtml}
</body>
</html>`;

fs.writeFileSync(TEMP_HTML, html);

(async () => {
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
  });
  const page = await browser.newPage();

  // file:// URL so relative image paths (./images/…) resolve.
  const fileUrl = 'file:///' + TEMP_HTML.replace(/\\/g, '/');
  await page.goto(fileUrl, { waitUntil: 'load', timeout: 60000 });

  await page.pdf({
    path: PDF_PATH,
    format: 'A4',
    printBackground: true,
    margin: { top: '18mm', right: '15mm', bottom: '18mm', left: '15mm' },
  });

  await browser.close();
  fs.unlinkSync(TEMP_HTML);
  const size = fs.statSync(PDF_PATH).size;
  console.log(`Wrote ${path.basename(PDF_PATH)} (${Math.round(size / 1024)} KB)`);
})();
