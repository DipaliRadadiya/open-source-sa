/**
 * DS-05 live check: a container deployed on the wrong port explains itself and
 * is fixed in one click, on both paths the backend has.
 *
 *   1. First deploy fails (status `failed`): Memos with port 8082 → the page says
 *      "Nothing answers on container port 8082 — the image listens on 5230" →
 *      "Use port 5230" saves and retries → the site answers 200.
 *   2. A running site broken later (status stays `active`): the Container screen
 *      saves 8082 → the same panel → "Use port 5230" recreates it in one request.
 *
 * Needs a panel running DS-03's backend. Not part of `npm test`:
 *
 *   PANEL=https://panel.example.com PANEL_USER=admin PANEL_PASS=... SHOTS=/dir \
 *     node tests/browser/docker-troubleshoot.mjs
 */
import { chromium } from "playwright";
import { mkdirSync } from "node:fs";

const PANEL = process.env.PANEL;
const USER = process.env.PANEL_USER ?? "admin";
const PASS = process.env.PANEL_PASS;
const SHOTS = process.env.SHOTS ?? "";
const TAG = `ts${Math.floor(Math.random() * 900 + 100)}`;
if (SHOTS) mkdirSync(SHOTS, { recursive: true });

let pass = 0;
let fail = 0;
const ok = (m) => { console.log(`PASS  ${m}`); pass += 1; };
const bad = (m, d) => { console.log(`FAIL  ${m} -- ${d}`); fail += 1; };
const is = (m, got, want) => (String(got) === String(want) ? ok(m) : bad(m, `expected [${want}] got [${got}]`));
const has = (m, hay, needle) => (String(hay).includes(needle) ? ok(m) : bad(m, `"${needle}" not in: ${String(hay).slice(0, 200)}`));
const lacks = (m, hay, needle) => (!String(hay).includes(needle) ? ok(m) : bad(m, `"${needle}" still present`));

const browser = await chromium.launch();
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const errors = [];
page.on("pageerror", (e) => errors.push(`[${page.url()}] pageerror: ${e.message}`));

async function goto(path) {
  await page.goto(PANEL + path, { waitUntil: "networkidle", timeout: 60000 });
  await page.waitForTimeout(1500);
}
const main = () => page.locator("main").innerText();
async function shot(name) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
}

/** Reload until `done(text)` or the time runs out; returns the last text. */
async function until(done, { tries = 50, wait = 6000 } = {}) {
  let text = "";
  for (let i = 0; i < tries; i += 1) {
    await page.reload({ waitUntil: "networkidle" });
    await page.waitForTimeout(1000);
    text = await main();
    if (done(text)) return text;
    await page.waitForTimeout(wait);
  }
  return text;
}

async function siteStatus(domain) {
  let status = 0;
  for (let i = 0; i < 20 && status !== 200; i += 1) {
    status = await page.request.get(`http://${domain}/`, { maxRedirects: 5 }).then((r) => r.status(), () => 0);
    if (status !== 200) await page.waitForTimeout(4000);
  }
  return status;
}

let siteName = `${TAG}site`;
let domain = "";
try {
  // ---- sign in
  await goto("/login");
  await page.waitForTimeout(2000);
  await page.locator("input[name=username]").pressSequentially(USER, { delay: 10 });
  await page.locator("input[name=password]").pressSequentially(PASS, { delay: 10 });
  await page.click("button:has-text('Sign in')");
  await page.waitForURL((x) => !x.pathname.endsWith("/login"), { timeout: 60000 });
  ok("signed in");

  // ---- create Memos on the wrong port, through the form
  await goto("/applications/create");
  await page.locator("button", { hasText: "Docker container" }).first().click();
  await page.waitForTimeout(2500);
  await page.locator("input[name=name]").fill(siteName);
  await page.waitForTimeout(1500);
  domain = await page.locator("input[name=domain]").inputValue();
  const search = page.getByRole("combobox", { name: "Docker image" });
  await search.click();
  await search.pressSequentially("memos", { delay: 40 });
  await page.getByRole("option", { name: /neosmemo\/memos/ }).first().click();
  await page.getByText("detected from the image").waitFor({ timeout: 30000 });
  await page.getByRole("button", { name: "Change", exact: true }).last().click();
  await page.locator("input[name=container_port]").fill("8082");

  const created = page.waitForResponse((r) => r.request().method() === "POST" && /\/api\/applications$/.test(new URL(r.url()).pathname), { timeout: 120000 });
  await page.locator("button:has-text('Create application')").click();
  const createRes = await created;
  is("create answered 201", createRes.status(), 201);
  const createJson = await createRes.json();
  has("create response warns about the port", (createJson.warnings ?? []).join(" "), "5230");
  await page.waitForURL(/\/applications\/\d+(\/|$)/, { timeout: 120000 });
  const siteId = page.url().match(/\/applications\/(\d+)/)?.[1];
  ok(`landed on the new site (${siteId})`);
  await page.getByText(/The image listens on 5230, not 8082/).first().waitFor({ timeout: 15000 })
    .then(() => ok("the port warning is shown after deploy"), () => bad("port warning toast", "not shown"));
  await shot("0-create-warning");

  // ---- the failure explains itself
  const failedText = await until((t) => /Nothing answers on container port 8082/.test(t));
  has("header names both ports", failedText, "Nothing answers on container port 8082 — the image listens on 5230");
  has("panel title", failedText, "The container isn't answering");
  has("the badge says Failed", await page.locator("h1").locator("xpath=..").innerText(), "Failed");
  is("the last log lines are shown", await page.locator("pre[aria-label='Last log lines']").count(), 1);
  is("the log has a copy button", await page.getByRole("button", { name: "Copy log" }).count(), 1);
  for (const label of ["Use port 5230", "Change port", "View full logs", "Redeploy"]) {
    is(`fix action "${label}"`, await page.getByRole(label === "View full logs" ? "link" : "button", { name: label, exact: true }).count(), 1);
  }
  const envLink = await page.getByRole("link", { name: "Edit environment variables" }).count();
  console.log(`INFO  env link offered: ${envLink === 1 ? "yes" : "no (no app_environment grant for this type)"}`);
  is("one retry button, not two", await page.getByRole("button", { name: "Retry setup" }).count(), 0);
  await shot("1-failed-desktop-light");

  // Change port opens prefilled with the image's port.
  await page.getByRole("button", { name: "Change port", exact: true }).click();
  is("Change port is prefilled with 5230", await page.locator("#container-fix-port").inputValue(), "5230");
  await shot("2-change-port-dialog");
  await page.getByRole("button", { name: "Cancel" }).click();

  // Dark, phone, German.
  await page.emulateMedia({ colorScheme: "dark" });
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForTimeout(1500);
  await shot("3-failed-desktop-dark");
  await page.emulateMedia({ colorScheme: "light" });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForTimeout(1500);
  const vw = await page.evaluate(() => [window.innerWidth, document.documentElement.scrollWidth]);
  is(`no horizontal overflow at 390 (innerWidth ${vw[0]})`, vw[1] <= vw[0], true);
  await shot("4-failed-phone-390");
  await page.setViewportSize({ width: 1440, height: 1000 });
  await context.addCookies([{ name: "NEXT_LOCALE", value: "de", url: PANEL }]);
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForTimeout(1500);
  has("German panel title", await main(), "Der Container antwortet nicht");
  await shot("5-failed-de");
  await context.addCookies([{ name: "NEXT_LOCALE", value: "en", url: PANEL }]);

  // The list says Failed too.
  await goto("/applications");
  has("list row says Failed", await page.locator("tr", { hasText: siteName }).first().innerText(), "Failed");
  await shot("6-list-failed");

  // ---- one click: Use port 5230 (failed first deploy → save + retry)
  await goto(`/applications/${siteId}`);
  const put = page.waitForResponse((r) => r.request().method() === "PUT" && /\/container$/.test(r.url()), { timeout: 60000 });
  const retry = page.waitForResponse((r) => r.request().method() === "POST" && /\/provision$/.test(r.url()), { timeout: 60000 });
  await page.getByRole("button", { name: "Use port 5230" }).click();
  is("Use port 5230 saved the port", (await put).status(), 200);
  is("…and retried the deploy", (await retry).status(), 202);
  const fixedText = await until((t) => !/Provisioning|Starting|Setting up/i.test(t) && /Running|Failed/.test(t));
  lacks("the failure panel is gone", fixedText, "The container isn't answering");
  has("the badge says Running", await page.locator("h1").locator("xpath=..").innerText(), "Running");
  is("the site answers 200 on port 5230", await siteStatus(domain), 200);
  await shot("7-fixed");

  // ---- path 2: a running site broken from the Container screen
  await goto(`/applications/${siteId}/container`);
  await page.getByRole("button", { name: "Change", exact: true }).first().click();
  await page.locator("input[name=container_port]").fill("8082");
  has("container card warns about the image's port", await main(), "The image listens on 5230");
  await shot("8-container-card-warning");
  const save = page.waitForResponse((r) => r.request().method() === "PUT" && /\/container$/.test(r.url()), { timeout: 180000 });
  await page.getByRole("button", { name: /^Save/ }).first().click();
  is("saving 8082 on a running site is refused with the reason", (await save).status(), 422);
  await goto(`/applications/${siteId}`);
  const activeFailed = await main();
  has("active site shows the same reason", activeFailed, "Nothing answers on container port 8082");
  has("active site badge says Failed", await page.locator("h1").locator("xpath=..").innerText(), "Failed");
  await shot("9-active-failed");
  const put2 = page.waitForResponse((r) => r.request().method() === "PUT" && /\/container$/.test(r.url()), { timeout: 180000 });
  await page.getByRole("button", { name: "Use port 5230" }).click();
  is("one click recreates it on 5230", (await put2).status(), 200);
  await page.getByText("The container is answering now.").first().waitFor({ timeout: 30000 })
    .then(() => ok("success toast"), () => bad("success toast", "not shown"));
  lacks("panel gone after the fix", await main(), "The container isn't answering");
  is("the site answers 200 again", await siteStatus(domain), 200);
} catch (error) {
  bad("run", error.stack?.split("\n").slice(0, 3).join(" | "));
} finally {
  // Clean up the site through the list's delete dialog.
  try {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await goto("/applications");
    const row = page.locator("tr", { hasText: siteName }).first();
    if (await row.count()) {
      await row.getByRole("button", { name: "Actions" }).click();
      await page.getByRole("menuitem", { name: "Delete" }).click();
      await page.waitForTimeout(1500);
      const del = page.locator("[role=alertdialog], [role=dialog]").first();
      const box = del.locator("#delete-app-docker");
      if (await box.count()) await box.check();
      await del.locator("input").last().fill(domain);
      await page.waitForTimeout(600);
      await del.getByRole("button", { name: /^Delete/ }).last().click();
      await page.locator("tr", { hasText: siteName }).first().waitFor({ state: "detached", timeout: 90000 });
      ok("test site deleted");
    }
  } catch (error) {
    bad("cleanup", error.message);
  }
  errors.length ? bad("no page errors", errors.join(" || ")) : ok("no page errors");
  console.log(`RESULT pass=${pass} fail=${fail}`);
  await browser.close();
  process.exit(fail ? 1 : 0);
}
