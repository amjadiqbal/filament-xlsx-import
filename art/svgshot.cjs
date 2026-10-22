// node svgshot.cjs in.svg out.png width height scale [transparent]
const { chromium } = require(process.env.PLAYWRIGHT_CORE);
const [, , inp, out, w, h, s, tr] = process.argv;
(async () => {
  const b = await chromium.launch({ channel: 'chrome', headless: true });
  const p = await b.newPage({ viewport: { width: +w, height: +h }, deviceScaleFactor: +s });
  await p.goto('file://' + require('path').resolve(inp));
  await p.screenshot({ path: out, omitBackground: !!tr });
  await b.close();
})();
