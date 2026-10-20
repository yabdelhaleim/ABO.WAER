// Final verification: log in, navigate to /orders, capture a clean
// screenshot at 1440x900 to show the user the page is working.
const pw = require("C:/laragon/bin/nodejs/node-v22/node_modules/playwright");

(async () => {
    const browser = await pw.chromium.launch({ headless: true, args: ["--no-sandbox"] });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();

    // Log in.
    await page.goto("http://127.0.0.1:8765/login", { waitUntil: "commit", timeout: 15000 });
    await page.waitForTimeout(500);
    await page.locator('input[name="identifier"]').fill("ahmed@example.com");
    await page.locator('input[name="password"]').fill("password");
    await page.locator('form').first().evaluate((f) => f.submit());
    await page.waitForTimeout(1500);

    // Go to /orders and capture.
    await page.goto("http://127.0.0.1:8765/orders", { waitUntil: "commit", timeout: 15000 });
    await page.waitForTimeout(1500);
    await page.screenshot({
        path: "C:/abo-store1/abo-store/gui-test-screenshots/orders/final-orders-authed-1440.png",
        fullPage: false,
    });
    const url = page.url();
    const title = await page.title();
    console.log("URL:", url);
    console.log("TITLE:", title);

    // Mobile capture (390) too.
    await page.setViewportSize({ width: 390, height: 800 });
    await page.waitForTimeout(400);
    await page.screenshot({
        path: "C:/abo-store1/abo-store/gui-test-screenshots/orders/final-orders-authed-390.png",
        fullPage: false,
    });

    await browser.close();
    console.log("done");
})().catch((e) => { console.error("fatal", e); process.exit(1); });