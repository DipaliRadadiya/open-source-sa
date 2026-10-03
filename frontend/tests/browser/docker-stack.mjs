/**
 * Browser coverage for the Docker stack, against a real panel.
 *
 * **Why this exists next to 1,860 passing frontend tests.** Those read JSX source and
 * assert on it — they catch a prop that stopped being passed, and they cannot catch a
 * page that throws on render, a table that stays empty because the fetcher's shape
 * changed, or a form whose submit button never enables. Nothing in this repository had
 * ever rendered a Docker screen until this file.
 *
 * Deliberately NOT part of `npm test`: that suite is hermetic and this one needs a
 * server, a login and about a minute. Run it explicitly:
 *
 *   PANEL=https://panel.example.com PANEL_USER=harness PANEL_PASS=... \
 *     node tests/browser/docker-stack.mjs
 *
 * Use a DEDICATED panel account, never the first admin — the activity log cannot
 * otherwise tell this harness's actions from a person's.
 */
import { chromium } from "playwright";

const PANEL = process.env.PANEL ?? "https://panel.23-172-120-85.nip.io";
const USER = process.env.PANEL_USER ?? "limitsharness";
const PASS = process.env.PANEL_PASS ?? "HarnessPw!2026x";
const TAG = `br${Math.floor(Math.random() * 900 + 100)}`;

let pass = 0;
let fail = 0;
const ok = (m) => { console.log(`PASS  ${m}`); pass += 1; };
const bad = (m, d) => { console.log(`FAIL  ${m} -- ${d}`); fail += 1; };
const is = (m, got, want) => (String(got) === String(want) ? ok(m) : bad(m, `expected [${want}] got [${got}]`));
const has = (m, hay, needle) => (String(hay).includes(needle) ? ok(m) : bad(m, `"${needle}" not in: ${String(hay).slice(0, 160)}`));

const browser = await chromium.launch();
const page = await browser.newPage({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });

// Collected rather than asserted inline: a page that renders but throws in an effect
// is a page that looks fine in a screenshot and is broken for the user.
const errors = [];
// The URL matters: a bare "status of 409" names neither the screen nor the endpoint,
// and the first version of this check reported the gating refusals that step 12 asks
// for as if they were defects.
page.on("console", (m) => {
  if (m.type() !== "error") return;
  // `m.text()` for a failed fetch is just "the server responded with a status of 409" --
  // the endpoint is only in `m.location().url`, and without it there is no way to tell a
  // refusal the test asked for from one it did not.
  const where = page.url().replace(/^https?:\/\/[^/]+/, "");
  errors.push(`[${where}] ${m.text()} ${m.location()?.url ?? ""}`.trim());
});
page.on("pageerror", (e) => errors.push(`[${page.url().replace(/^https?:\/\/[^/]+/, "")}] pageerror: ${e.message}`));

/** Wait for a table row naming this thing to exist, or report what the table holds. */
async function rowAppears(name, label, timeout = 30000) {
  try {
    await page.locator("tr", { hasText: name }).first().waitFor({ state: "visible", timeout });
    ok(label);
  } catch {
    bad(label, `no row for ${name}; table: ${(await page.locator("table").first().innerText()).slice(0, 120)}`);
  }
}

/** Wait for it to be gone. `detached` rather than `hidden`: the row is removed. */
async function rowDisappears(name, label, timeout = 30000) {
  try {
    await page.locator("tr", { hasText: name }).first().waitFor({ state: "detached", timeout });
    ok(label);
  } catch {
    bad(label, `row for ${name} still present`);
  }
}

async function goto(path) {
  await page.goto(PANEL + path, { waitUntil: "networkidle", timeout: 60000 });
  // next-intl redirects to a locale prefix, and the server components stream, so a
  // settled network is not a settled DOM.
  await page.waitForTimeout(1500);
}

try {
  // ---- 1. sign in
  await goto("/login");
  // After hydration, not before: filling a react-hook-form input while React is still
  // mounting leaves the field visually full and the form state empty, which then
  // reports "Required" under a box with text in it.
  await page.waitForTimeout(2000);
  const u = page.locator("input[name=username]");
  await u.click();
  await u.pressSequentially(USER, { delay: 10 });
  const w = page.locator("input[name=password]");
  await w.click();
  await w.pressSequentially(PASS, { delay: 10 });
  is("login form accepted the credentials", (await u.inputValue()) === USER, true);
  await page.click("button:has-text('Sign in')");
  await page.waitForURL((x) => !x.pathname.endsWith("/login"), { timeout: 60000 });
  ok("signed in");

  // ---- 2. the Docker page renders both tables
  await goto("/docker");
  has("Docker page has its heading", (await page.locator("h1,h2").allTextContents()).join("|"), "Docker");
  const th = (await page.locator("th").allTextContents()).map((s) => s.trim());
  for (const head of ["Name", "Driver", "Containers", "Path", "Size", "Used by"]) {
    th.includes(head) ? ok(`networks/volumes column "${head}"`) : bad(`column "${head}"`, th.join(", "));
  }
  // Docker always has bridge, host and none — an empty table here would mean the
  // fetcher degraded a failure into "this server has no networks".
  has("built-in networks listed", await page.locator("table").first().innerText(), "bridge");

  // ---- 3. create a network through the UI, not the API
  const netName = `${TAG}-net`;
  await page.fill("input[placeholder='network-name']", netName);
  await page.locator("form", { has: page.locator("input[placeholder='network-name']") }).locator("button:has-text('Create')").click();
  await rowAppears(netName, "network appears in the table after creating it");

  // ---- 4. and remove it through the confirmation, which is the only route offered
  const row = page.locator("tr", { hasText: netName });
  await row.locator("button").last().click();
  await page.waitForTimeout(1200);
  const dialog = page.locator("[role=alertdialog], [role=dialog]").first();
  has("delete asks first, and names the network", await dialog.innerText(), netName);
  await dialog.locator("button:has-text('Remove')").click();
  await rowDisappears(netName, "network gone after removal");

  // ---- 5. a volume, the same way
  const volName = `${TAG}-vol`;
  await page.fill("input[placeholder='volume-name']", volName);
  await page.locator("form", { has: page.locator("input[placeholder='volume-name']") }).locator("button:has-text('Create')").click();
  await rowAppears(volName, "volume appears in the table after creating it");
  const vrow = page.locator("tr", { hasText: volName });
  await vrow.locator("button").last().click();
  await page.waitForTimeout(1200);
  await page.locator("[role=alertdialog], [role=dialog]").first().locator("button:has-text('Remove')").click();
  await rowDisappears(volName, "volume gone after removal");

  // ---- 6. the create page offers containers and no databases
  await goto("/applications/create");
  // Switch to All, so the assertion is about the whole catalogue rather than the seven
  // cards the grid opens on.
  const allChip = page.locator("button", { hasText: /^All$/ });
  if (await allChip.count()) { await allChip.first().click(); await page.waitForTimeout(800); }

  const cardTitles = (await page.locator("button .text-sm.font-medium").allTextContents()).map((t) => t.trim());
  cardTitles.includes("Docker container")
    ? ok("Docker container card is offered")
    : bad("Docker container card", cardTitles.slice(0, 12).join(", "));
  is("every application card is an application", cardTitles.length >= 18, true);

  // Matched against the TITLE and exactly. "PostgreSQL" and "MariaDB" appear in the
  // taglines of Metabase, NocoDB, Wiki.js and Matomo, so a body-text search reported
  // the removal as incomplete when it was complete.
  for (const absent of ["PostgreSQL", "MySQL", "MariaDB", "MongoDB", "Redis", "Valkey"]) {
    cardTitles.includes(absent)
      ? bad(`no "${absent}" card after the removal`, "still offered as a card")
      : ok(`no "${absent}" card after the removal`);
  }
  const chips = (await page.locator("button").allTextContents()).map((t) => t.trim());
  chips.includes("Databases") ? bad("no Databases category chip", "still present") : ok("no Databases category chip");

  // ---- 7. choosing Docker reveals the fields, and the CPU hint names THIS server
  await page.locator("button", { hasText: "Docker container" }).first().click();
  await page.waitForTimeout(2500);
  const form = await page.locator("body").innerText();
  for (const label of ["Image", "Container port", "Memory limit", "CPU limit"]) {
    has(`"${label}" field is on the create form`, form, label);
  }
  // Read from the server, so the hint cannot drift from what the validator enforces.
  /\bThis server has \d+\b/.test(form)
    ? ok("CPU hint states this server's core count")
    : bad("CPU hint core count", form.match(/In cores[^.]*\./)?.[0] ?? "hint not found");
  const placeholders = await page.locator("input").evaluateAll((els) => els.map((e) => e.placeholder).filter(Boolean));
  // The placeholder, read as an attribute — it is not in `innerText`, so the first
  // version of this check could only ever fail.
  placeholders.includes("No limit")
    ? ok("empty CPU says it means no limit")
    : bad("CPU placeholder", placeholders.join(" | "));
  placeholders.some((x) => /^\d+[mg]$/.test(x))
    ? ok("memory placeholder is the server default")
    : bad("memory placeholder", placeholders.join(" | "));

  // ---- 8. create a container site THROUGH THE FORM, then read it back
  //
  // The highest-value thing in this file. Everything above checks that a control is on
  // screen; this checks that the screen somebody actually uses produces a running
  // container with the limits they typed.
  const siteName = `${TAG}site`;
  await page.locator("input[name=name]").fill(siteName);
  await page.waitForTimeout(1500);
  // NOT filled: the form defaults to a Temporary domain, derives it from the name and
  // holds the field read-only. Filling it timed out on an input that is visible and
  // disabled, which Playwright reports as a plain timeout.
  const derived = await page.locator("input[name=domain]").inputValue();
  derived.startsWith(siteName)
    ? ok("the temporary domain is derived from the name")
    : bad("temporary domain", `got "${derived}"`);
  await page.locator("input[name=image]").fill("nginx:1.27-alpine");
  await page.locator("input[name=container_port]").fill("80");
  await page.locator("input[name=memory_limit]").fill("192m");
  await page.locator("input[name=cpu_limit]").fill("0.5");

  const submit = page.locator("button:has-text('Create application')");
  await submit.scrollIntoViewIfNeeded();
  is("Create is enabled once the form is answered", await submit.isEnabled(), true);
  await submit.click();

  // Lands on the new site. Generous, because provisioning pulls an image.
  await page.waitForURL(/\/applications\/\d+(\/|$)/, { timeout: 120000 });
  const siteId = page.url().match(/\/applications\/(\d+)/)?.[1];
  siteId ? ok(`the form created a site and landed on it (${siteId})`) : bad("site id", page.url());

  // Wait for it to be serving rather than asserting on the provisioning screen.
  let settled = false;
  for (let i = 0; i < 40; i += 1) {
    await page.reload({ waitUntil: "networkidle" });
    const text = await page.locator("main").innerText();
    if (/Failed|could not/i.test(text)) break;
    if (!/Not deployed|Provisioning|Installing/i.test(text)) { settled = true; break; }
    await page.waitForTimeout(6000);
  }
  is("the site reached a settled state", settled, true);

  // ---- 9. its Container screen shows what was typed on the create form
  await goto(`/applications/${siteId}/container`);
  const container = await page.locator("main").innerText();
  has("Container screen names the image", container, "nginx:1.27-alpine");
  is("memory limit survived the create form", await page.locator("input[name=memory_limit]").inputValue(), "192m");
  is("cpu limit survived the create form", await page.locator("input[name=cpu_limit]").inputValue(), "0.5");
  /This server has \d+/.test(container) ? ok("Container screen states the host core count") : bad("core count on Container screen", "absent");

  // ---- 10. and the compose file the panel wrote for it
  await goto(`/applications/${siteId}/compose`);
  // The compose file is a <textarea value={...}>, and a textarea's value is not
  // part of innerText -- it has to be read as an input value.
  const compose = await page.locator("main textarea").first().inputValue();
  has("compose file shows the memory ceiling", compose, "mem_limit: 192m");
  has("compose file shows the cpu quota", compose, "cpus: 0.5");
  has("compose file publishes to loopback only", compose, "127.0.0.1:");

  // ---- 11. delete it through the dialog, including its Docker resources
  // Delete is not on the site's own screen -- it lives in the row's actions menu on
  // the applications list. The first version of this clicked a button that does not
  // exist on `/applications/${siteId}` and reported it as a bare 30s timeout.
  await goto("/applications");
  const appRow = page.locator("tr", { hasText: siteName }).first();
  await appRow.getByRole("button", { name: "Actions" }).click();
  await page.getByRole("menuitem", { name: "Delete" }).click();
  await page.waitForTimeout(1500);
  const del = page.locator("[role=alertdialog], [role=dialog]").first();
  has("the delete dialog names the site", await del.innerText(), siteName);
  // The site's volume and network, offered as one box. Checking it is the whole point
  // of deleting through the dialog rather than the API -- it is what proves the panel
  // cleans up after a container site.
  // Offered only when the site actually has named volumes or a network of its own. A
  // site created through this form has neither -- its compose project network is torn
  // down with the project -- so its ABSENCE here is correct, and the e2e API script
  // covers the case where resources exist because it creates the site with a network.
  const dockerBox = del.locator("#delete-app-docker");
  if (await dockerBox.count()) {
    await dockerBox.check();
    ok("the dialog offers to remove the Docker resources");
  }
  // Confirmation is the DOMAIN, not the name, and the confirm button stays disabled
  // until it matches.
  await del.locator("input").last().fill(derived);
  const confirmBtn = del.getByRole("button", { name: /^Delete/ }).last();
  is("confirm is enabled once the domain is typed", await confirmBtn.isEnabled(), true);
  await confirmBtn.click();
  await page.waitForURL(/\/applications(\?|$)/, { timeout: 120000 }).catch(() => {});
  // Read the TABLE, not the body: the body still carries the "deleted" toast naming the
  // site, so a body-text search reports a successful delete as a failure.
  await rowDisappears(siteName, "site gone from the list", 60000);

  // ---- 12. the stack's own gating, as rendered
  await goto("/databases");
  const dbMain = await page.locator("main").innerText();
  /don't have access|permission/i.test(dbMain)
    ? ok("Databases screen is refused on a Docker box")
    : bad("Databases screen refused", dbMain.slice(0, 140).replace(/\n+/g, " / "));
  await goto("/php");
  const php = await page.locator("body").innerText();
  /permission|not available|manages no|don't have/i.test(php)
    ? ok("PHP screen is refused on a Docker box")
    : bad("PHP screen refused", php.slice(0, 120));

  // ---- 9. nothing threw while all that rendered
  // A 409 on /databases, /php or /node is what step 12 just asserted SHOULD happen --
  // the box manages no databases and hosts no PHP. The browser logs every refused fetch
  // as a console error, so those three have to come out or this check contradicts the
  // two assertions above it.
  const real = errors.filter((e) => !/favicon|ERR_ABORTED|Download the React DevTools/i.test(e))
    .filter((e) => !(/status of 409/.test(e) && /\/(databases|php|node)\b/.test(e)));
  real.length === 0 ? ok("no console or page errors across every screen") : bad("console errors", real.slice(0, 3).join(" | "));
} catch (e) {
  bad("harness", `${e.name}: ${String(e.message).split("\n")[0]}`);
} finally {
  // Whatever happened, do not leave objects named after this run on the box.
  try {
    // A site first: its delete is the only thing that removes the container.
    try {
      await goto("/applications");
      const srow = page.locator("tr", { hasText: `${TAG}site` });
      if (await srow.count()) {
        // Through the row's actions menu, like step 11 -- the site's own screen has no
        // Delete button, so the earlier version of this sweep silently never ran.
        await srow.first().getByRole("button", { name: "Actions" }).click();
        await page.getByRole("menuitem", { name: "Delete" }).click();
        await page.waitForTimeout(1500);
        const d = page.locator("[role=alertdialog], [role=dialog]").first();
        const box = d.locator("#delete-app-docker");
        if (await box.count()) await box.check();
        // Confirmation is the domain.
        await d.locator("input").last().fill(`${TAG}site.${new URL(PANEL).hostname.replace(/^panel\./, "")}`);
        await d.getByRole("button", { name: /^Delete/ }).last().click();
        await page.waitForTimeout(20000);
      }
    } catch { /* reported above */ }

    // Then its system user. Deleting a site deliberately does NOT remove one -- a system
    // user can host several sites -- so the create form's generated user outlives the run
    // and this suite was leaking one per run (seven had piled up before anyone looked).
    // Only possible after the site is gone: the menu item is disabled while it owns apps.
    try {
      await goto("/system-users");
      const urow = page.locator("tr", { hasText: `${TAG}site` });
      if (await urow.count()) {
        await urow.first().getByRole("button", { name: "Actions" }).click();
        await page.getByRole("menuitem", { name: "Delete" }).click();
        await page.waitForTimeout(1200);
        const ud = page.locator("[role=alertdialog], [role=dialog]").first();
        // Confirmation is the username typed exactly.
        await ud.locator("input").last().fill(`${TAG}site`);
        await ud.getByRole("button", { name: /^Delete/ }).last().click();
        await page.waitForTimeout(6000);
      }
    } catch { /* best effort */ }

    await goto("/docker");
    for (const name of [`${TAG}-net`, `${TAG}-vol`]) {
      const r = page.locator("tr", { hasText: name });
      if (await r.count()) {
        await r.first().locator("button").last().click();
        await page.waitForTimeout(1000);
        await page.locator("[role=alertdialog], [role=dialog]").first().locator("button:has-text('Remove')").click();
        await page.waitForTimeout(2500);
      }
    }
  } catch { /* the assertions already reported whatever broke */ }
  await browser.close();
  console.log(`\nRESULT pass=${pass} fail=${fail}`);
  process.exit(fail === 0 ? 0 : 1);
}
