import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { usageStatus, usageTone } from "../lib/metrics/usage-level.js";

const statCard = fs.readFileSync("components/ui/stat-card.jsx", "utf8");
const statCards = fs.readFileSync("components/dashboard/stat-cards.jsx", "utf8");
const section = fs.readFileSync("components/dashboard/live-metrics-section.jsx", "utf8");
const chartCard = fs.readFileSync("components/dashboard/live-chart-card.jsx", "utf8");
const killButton = fs.readFileSync("components/dashboard/kill-process-button.jsx", "utf8");

const LOCALES = fs
  .readFileSync("i18n/routing.js", "utf8")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((code) => code.trim().replace(/['"]/g, ""))
  .filter(Boolean);

const messages = Object.fromEntries(
  LOCALES.map((l) => [l, JSON.parse(fs.readFileSync(`messages/${l}.json`, "utf8"))]),
);

/* -------------------------------------------------------------------------
 * The status word
 * ---------------------------------------------------------------------- */

test("the word and the bar colour come from one ladder", () => {
  /*
   * Two independent threshold tables is how a card ends up with an amber bar
   * beside the label "Normal". `usageTone` is now derived from `usageStatus`
   * rather than re-testing the numbers, so they cannot disagree — this walks
   * every percent to prove the derivation holds at the boundaries.
   */
  const expected = { normal: "primary", watch: "warning", high: "destructive" };
  for (let p = 0; p <= 100; p += 1) {
    assert.equal(usageTone(p), expected[usageStatus(p)], `they disagree at ${p}%`);
  }

  // The boundaries themselves, stated rather than inferred from the loop.
  assert.equal(usageStatus(74.9), "normal");
  assert.equal(usageStatus(75), "watch");
  assert.equal(usageStatus(89.9), "watch");
  assert.equal(usageStatus(90), "high");
});

test("no percentage means no word", () => {
  /*
   * A machine with no swap and a disk the collector could not read both arrive
   * as null. Calling either "Normal" would be a reassurance nobody measured.
   */
  assert.equal(usageStatus(null), null);
  assert.equal(usageStatus(undefined), null);
  // And the colour still has to resolve to something renderable.
  assert.equal(usageTone(null), "primary");
});

test("a machine with no swap is Off; a disk that reported nothing is Unknown", () => {
  /*
   * These are NOT the same state and must not share a word. Plenty of servers
   * run without swap deliberately — that is a fact about the machine. A disk
   * reporting 0 total is a measurement that failed, and there is no such thing
   * as a server without a disk.
   */
  assert.match(
    statCards,
    /Number\(swap\?\.total\) > 0 \? statusFor\(swap\?\.percent\) : statusFor\(null, "off"\)/,
  );
  assert.match(
    statCards,
    /Number\(disk\?\.total\) > 0 \? statusFor\(disk\?\.percent\) : statusFor\(null, "unknown"\)/,
  );

  for (const l of LOCALES) {
    const s = messages[l].serverDashboard.status;
    assert.notEqual(s.off, s.unknown, `${l} uses one word for Off and Unknown`);
    assert.notEqual(s.normal, s.high, `${l} uses one word for Normal and High`);
    assert.notEqual(s.normal, s.watch, `${l} uses one word for Normal and Watch`);
  }
});

test("CPU says nothing until it has been measured", () => {
  /*
   * The first rate needs two samples. The card already says "Measuring…" for
   * those few seconds; a level word beside it would be describing a number we
   * do not have.
   */
  assert.match(statCards, /status=\{ratesReady \? statusFor\(cpu\?\.percent\) : null\}/);
});

test("the badge is hidden while the number is still a skeleton", () => {
  /*
   * A level word over a skeleton is a claim about a value that has not landed.
   *
   * This used to be a `!loading` guard on the badge itself. The badge now lives
   * in the loaded branch of the loading ternary, so the guard is structural —
   * which is stronger, but only if the badge really is inside that branch.
   */
  const loaded = statCard.slice(statCard.indexOf("        ) : ("), statCard.indexOf("</CardContent>"));
  assert.match(loaded, /<Badge/, "the badge left the loaded branch");

  const loadingBranch = statCard.slice(
    statCard.indexOf("{loading ? ("),
    statCard.indexOf("        ) : ("),
  );
  assert.doesNotMatch(loadingBranch, /<Badge/, "a badge renders over the skeleton");
});

test("the label owns its row", () => {
  /*
   * Five cards across a 1184px content column leave about 82px for a label once
   * the icon chip and a badge have taken theirs — enough for "CPU" and nothing
   * else. Measured across all 8 locales at 1280/1440/1600: every language but
   * English and Japanese clipped at least one label, Hindi clipped four.
   *
   * So the badge sits on the bottom row with the helper line instead. Shortening
   * the words one locale at a time would have been chasing whichever language
   * was next.
   */
  const header = statCard.slice(
    statCard.indexOf('<div className="flex min-h-8 items-center gap-2.5">'),
    statCard.indexOf("{loading ? ("),
  );
  assert.doesNotMatch(header, /<Badge/, "the badge is back on the label row");
  assert.match(statCard, /\{sub \|\| status \?/, "the bottom row no longer holds the badge");
  // And that row keeps its height whether it holds one, both or neither, or the
  // five cards stop being the same height.
  assert.match(statCard, /mt-2\.5 flex min-h-5 items-center justify-between/);
});

test("every bar has the same groove, and it is cool rather than grey", () => {
  /*
   * Two separate things, both of which were once wrong.
   *
   * The track must not be a tint of its own tone — that gave a High card a pink
   * groove and a Normal card a blue one, five bars with no two alike.
   *
   * And it takes the brand hue, not a neutral grey. Sampled from the reference
   * we were matching: its track is #E8EEF7, a blue-grey. Ours renders
   * rgb(228,238,255) at 8% — same lightness, bluer, because the hue comes from
   * our own primary rather than from the reference's. 12% was tried first and
   * measured too strong (217,232,254 against the reference's 232,238,247).
   */
  assert.match(statCard, /const TRACK = "bg-primary\/8"/);

  const code = statCard.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  assert.doesNotMatch(code, /track:/, "a per-tone track is back");
});

test("the level word is opt-in, so the other two pages are untouched", () => {
  /*
   * StatCard is shared with the Disk Cleaner summary and the database engine
   * cards. Both measure a single fixed thing where a level adds nothing, so a
   * default-on badge would have appeared on screens that never asked.
   */
  assert.match(statCard, /status = null,/, "status no longer defaults to off");

  for (const file of [
    "components/disk-cleaner/disk-summary.jsx",
    "components/databases/engine-status-cards.jsx",
  ]) {
    assert.doesNotMatch(
      fs.readFileSync(file, "utf8"),
      /\bstatus=\{/,
      `${file} started passing a status — it is outside the dashboard`,
    );
  }
});

test("measured-and-fine and nothing-to-measure are told apart by colour", () => {
  /*
   * Normal is green, not a filled grey.
   *
   * Grey left the ladder relying on filled-vs-hollow grey to separate "healthy"
   * from "could not measure" — seen on a real panel where Disk said Unknown
   * beside four Normals in the same colour. That is the one distinction that
   * must not be subtle: a machine with no swap is not a machine with healthy
   * swap.
   */
  assert.match(statCard, /normal: "success"/);
  assert.match(statCard, /watch: "warning"/);
  assert.match(statCard, /high: "destructive"/);
  // Both no-reading states stay hollow, and must not drift into a filled one.
  assert.match(statCard, /off: "outline"/);
  assert.match(statCard, /unknown: "outline"/);

  const code = statCard.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  assert.doesNotMatch(
    code,
    /normal: "secondary"/,
    "Normal is grey again, which makes it a shade away from Off and Unknown",
  );

  // Every variant named here has to exist on Badge, or it silently falls back.
  const badge = fs.readFileSync("components/ui/badge.jsx", "utf8");
  for (const variant of ["success", "warning", "destructive", "outline"]) {
    assert.match(badge, new RegExp(`\\b${variant}:`), `Badge has no ${variant} variant`);
  }
});

/* -------------------------------------------------------------------------
 * Section headings
 * ---------------------------------------------------------------------- */

test("both clocks are named, not just the older one", () => {
  /*
   * "Last 24 hours" was a lone muted line, which labelled the block below it
   * and left the block above unnamed — so the page read as some charts, then a
   * section. The live group gets a peer heading.
   */
  assert.match(section, /title=\{t\("liveLabel"\)\}/);
  assert.match(section, /title=\{t\("historyLabel"\)\}/);
  assert.match(section, /<h2 className="flex items-center gap-2 text-sm font-semibold">/);

  // And the old floating label is gone rather than left beside the new one.
  const code = section.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  assert.doesNotMatch(code, /pt-2">\s*<History/, "the bare history label is back");
});

test("the cards inside a section drop to h3", () => {
  /*
   * The section heading is the h2. Leaving the chart titles at h2 would make a
   * heading outline where a section and the cards it contains are peers.
   * Processes keeps h2 — it is a direct child of the page, not of a section.
   */
  // Attribute, not the opening line: the tag is wrapped across lines now.
  assert.match(chartCard, /<CardTitle\s[\s\S]{0,80}?as="h3"/);
  assert.match(
    fs.readFileSync("components/dashboard/processes-card.jsx", "utf8"),
    /<CardTitle as="h2"/,
  );
});

test("the live pill belongs to the live heading", () => {
  // As a floating row above the cards it belonged to nothing in particular.
  assert.match(section, /<SectionHeading icon=\{Radio\} title=\{t\("liveLabel"\)\}>\s*<LiveStatus/);
});

test("the info tiles leave no hole at the two-column step", () => {
  /*
   * Five tiles with identity spanning two: at `sm` that leaves the kernel alone
   * on the last row with an empty cell beside it. Found by rendering at 768px,
   * where it was the only ragged edge on the page — a gap no single-width
   * screenshot shows, because at 1440 all five sit on one line.
   */
  const info = fs.readFileSync("components/dashboard/server-info-card.jsx", "utf8");
  assert.match(info, /className="sm:col-span-2 xl:col-span-1"/);
});

/* -------------------------------------------------------------------------
 * Chart pills
 * ---------------------------------------------------------------------- */

test("a reading is named in words, not with a bare arrow glyph", () => {
  /*
   * The pills led with `↓`, which is a text glyph rather than an icon, reads
   * as "down arrow" to a screen reader, and means nothing on a Read/Write
   * chart where neither direction is down.
   */
  for (const file of [
    "components/dashboard/network-io-chart.jsx",
    "components/dashboard/disk-io-chart.jsx",
  ]) {
    const source = fs.readFileSync(file, "utf8");
    const code = source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
    assert.doesNotMatch(code, /[↓↑]/, `${file} still labels a reading with an arrow`);
    assert.match(code, /label=\{t\("charts\./, `${file} does not name its series`);
    assert.match(code, /dotClassName="bg-chart-[12]"/, `${file} lost its series colour`);
  }
});

test("the value keeps foreground contrast; only the dot carries the series colour", () => {
  /*
   * The whole pill used to be `bg-chart-2/10 text-chart-2`, which put the
   * number in a colour chosen to be legible as a 2px line rather than as text.
   */
  assert.match(chartCard, /font-medium tabular-nums text-foreground/);
  assert.match(chartCard, /size-2 shrink-0 rounded-full/);
});

test("the live pills sit on the chart's title line", () => {
  /*
   * They were on a row beneath the description, and the reason was real:
   * inline against the whole title BLOCK, Disk I/O's four numbers did not fit
   * beside a 262px block in a 560px card, so its header grew and the two plots
   * started 42px apart.
   *
   * The block is only that wide because the description sits inside it. Beside
   * the title TEXT there is ~428px and Disk's pills need 334px, which is where
   * they are now.
   *
   * Where there is not, they take a line of their own — see the two tests
   * below, which are the whole reason this is safe to do.
   */
  const title = chartCard.slice(chartCard.indexOf("<CardTitle"), chartCard.indexOf("</CardTitle>"));
  assert.match(title, /\{badges\}/, "the pills left the title line");
  // One render site only — a second would draw them twice.
  assert.equal((chartCard.match(/\{badges\}/g) ?? []).length, 1);
});

test("the heading is never the thing that gives way", () => {
  /*
   * As a bare text node the title was an anonymous flex item — the only
   * shrinkable thing on a row with a shrink-0 pill group — so at 1280 English
   * it rendered as "Disk / I/O" on two lines, and at 1024–1366 German BOTH
   * headings broke. The pills yield now, not the words.
   *
   * justify-between rather than ml-auto is load-bearing: justify-content
   * applies per line, so a pill group that wraps is alone on its line and
   * lands at the start. Pinned right it drew one right-aligned pill per row on
   * a phone.
   */
  const title = chartCard.slice(chartCard.indexOf("<CardTitle"), chartCard.indexOf("</CardTitle>"));
  assert.match(title, /whitespace-nowrap">\{title\}/, "the heading can break mid-phrase again");
  assert.match(title, /justify-between/);
  assert.doesNotMatch(title, /ml-auto/, "wrapped pills would pin right");
  assert.match(title, /flex-wrap/, "the pills need a line to fall to");
});

test("the plot is anchored to the bottom of the card, not the header", () => {
  /*
   * What the eye actually checks in a pair of charts is whether the two plots
   * start on the same line. Both cards are the same height — the grid stretches
   * them and Card is h-full — so bottom-anchoring the content makes that true
   * whatever the headers do, and a one-line-taller header costs nothing.
   *
   * Without it the pair is 38px out at every width where one card's pills wrap
   * and the other's do not: en 1024/1280, de 1440, ja 1024–1440.
   */
  const code = chartCard.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");
  assert.match(code, /<CardContent className="[^"]*\bmt-auto\b/);
});

test("both I/O pills render the same component, so they cannot drift apart", () => {
  // Disk carries four numbers and network two; as two hand-built badges they
  // read as different components sitting side by side.
  assert.match(chartCard, /export function ChartPill/);
  assert.match(fs.readFileSync("components/dashboard/disk-io-chart.jsx", "utf8"), /note=\{t\(/);
});

/* -------------------------------------------------------------------------
 * Process table
 * ---------------------------------------------------------------------- */

test("the stop button has exactly one tooltip", () => {
  /*
   * Button renders its own ReasonTooltip whenever `disabledReason` is passed,
   * and stands down only for a parent supplying the reason through context.
   * The wrapper here is a hand-rolled Radix Tooltip, which sets no context — so
   * without permission this had two tooltips saying the same sentence and two
   * tab stops (both wrapper spans take tabIndex={0}) for one dead button. On a
   * touch screen the inner one is a Popover nested in a TooltipTrigger.
   */
  const code = killButton.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  assert.doesNotMatch(code, /disabledReason=/, "the second tooltip is back");

  // The remaining one still has to cover both states and still be labelled.
  assert.match(killButton, /aria-label=\{t\("kill\.action"\)\}/);
  assert.match(killButton, /canManage \? t\("kill\.action"\) : t\("kill\.noPermission"\)/);
});

test("the command column reads as the row's identity", () => {
  /*
   * It was the only cell in BOTH the smallest size and the quietest colour —
   * the row led with its PID instead of with what the process is.
   */
  const table = fs.readFileSync("components/dashboard/process-table.jsx", "utf8");
  assert.doesNotMatch(
    table,
    /block w-full truncate font-mono text-xs text-muted-foreground focus-visible/,
    "the command is muted again",
  );
  // The em-dash placeholder stays muted: there is no identity to show.
  assert.match(table, /block w-full truncate font-mono text-xs text-muted-foreground">\s*—/);
});

/* -------------------------------------------------------------------------
 * Empty states
 * ---------------------------------------------------------------------- */

const info = fs.readFileSync("components/dashboard/server-info-card.jsx", "utf8");
const emptyState = fs.readFileSync("components/data-table/empty-state.jsx", "utf8");
const processTable = fs.readFileSync("components/dashboard/process-table.jsx", "utf8");

test("the services line says something in every case it can be in", () => {
  /*
   * The old condition was `health?.total && !down.length`, which is silent on
   * three situations that mean three different things. Only one of them — no
   * permission, or a failed fetch — deserves silence. The other two were
   * reported as the summary having vanished, and left the runtimes badges as
   * the only thing in the footer.
   */
  assert.match(info, /function ServiceHealthLine/);
  assert.match(info, /if \(!health\) return null;/, "a missing answer must stay silent");
  assert.match(info, /if \(down\.length\)/);
  assert.match(info, /if \(!health\.total\)[\s\S]{0,120}info\.noServices/);
  assert.match(info, /info\.servicesOk/);

  const code = info.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  assert.doesNotMatch(
    code,
    /health\?\.total && !down\.length/,
    "back to one condition that hides two different states",
  );
});

test("the runtimes are in a real footer, not a bordered row of content", () => {
  // As a plain div they sat in the card's own padding with a hairline above,
  // so when the services half was empty they read as an unfinished line.
  assert.match(info, /<CardFooter/);
  assert.match(info, /import \{ Card, CardContent, CardFooter \}/);
});

test("the empty-services wording is the one the Services page already ships", () => {
  // Caught by the one-voice gate first: "Keine Dienste erkannt" vs the existing
  // "Keine Dienste gefunden". Two German sentences for one English string.
  for (const l of LOCALES) {
    assert.equal(
      messages[l].serverDashboard.info.noServices,
      messages[l].services.empty.title,
      `${l} says this two ways`,
    );
  }
});

test("only the 24h cards collapse when empty", () => {
  /*
   * The live I/O cards hold the full plot height for the two polls they wait —
   * collapsing them would make the page jump on every load. The 24h cards may
   * never get a sample, so holding 288px of white there is what read as
   * unfinished.
   */
  for (const file of ["server-load-chart", "resource-usage-chart"]) {
    assert.match(
      fs.readFileSync(`components/dashboard/${file}.jsx`, "utf8"),
      /compactEmpty/,
      `${file} still reserves a full plot for a sample that may never come`,
    );
  }
  for (const file of ["network-io-chart", "disk-io-chart"]) {
    assert.doesNotMatch(
      fs.readFileSync(`components/dashboard/${file}.jsx`, "utf8"),
      /compactEmpty/,
      `${file} would now collapse and re-expand on every page load`,
    );
  }
  assert.match(chartCard, /compactEmpty = false/, "it stopped being opt-in");
});

test("the compact empty state is opt-in, so 32 other screens keep theirs", () => {
  assert.match(emptyState, /compact = false/);
  // Solid and filled inside a card; dashed only when it fills a page, where
  // "nothing here, put something here" is the right note.
  assert.match(emptyState, /compact \? "gap-2 bg-muted\/40 px-6 py-8" : "gap-3 border border-dashed/);
});

test("the two no-rows states describe themselves and not each other", () => {
  /*
   * `failed` is the request: non-2xx, or a body the schema rejected. An empty
   * `data` is a 200 whose list had nothing in it. They were told apart in code
   * already and then both described the same cause — the empty branch claimed
   * the list could not be READ, which is the failed branch's story.
   *
   * "Busy" is fair in the empty copy even though there is no threshold: the
   * endpoint only ever returns the top processes by CPU, so an empty response
   * is the server reporting none of them. What neither may do is promise a
   * mechanism — copy inviting someone to wait for usage to cross a threshold
   * would invite them to wait forever, because `ServerMetrics::processes()`
   * just slices the top 25 of `ps --sort=-%cpu`.
   */
  assert.match(processTable, /title=\{t\("processes\.unavailable"\)\}/);
  assert.match(processTable, /title=\{t\("processes\.empty"\)\}/);

  const p = JSON.parse(fs.readFileSync("messages/en.json", "utf8")).serverDashboard.processes;
  assert.doesNotMatch(p.empty, /threshold/i);
  assert.doesNotMatch(p.emptyDetail, /threshold/i);

  // The empty state must not claim a read failure, and the failed state must.
  assert.doesNotMatch(p.emptyDetail, /could not (read|reach)/i);
  assert.match(p.unavailableDetail, /could not reach/i);
  assert.notEqual(p.empty, p.unavailable);
});

test("a failed fetch goes through the same empty state as an empty list", () => {
  /*
   * The card used to short-circuit `failed` into a bare grey sentence, which
   * made ProcessTable's own failed branch unreachable and left the two states
   * looking nothing alike — one framed, one a line of text.
   */
  const card = fs.readFileSync("components/dashboard/processes-card.jsx", "utf8");
  const code = card.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  assert.doesNotMatch(code, /failed \? \(/, "the card decides again instead of the table");
  assert.match(card, /<ProcessTable/);
});

test("the four chart plots start at the same height", () => {
  /*
   * Disk I/O carries four numbers in its pills against Network's two, so Disk's
   * description wrapped and Network's did not — and in a 2x2 grid the two plots
   * beside each other began 20px apart. The grid equalises card height and says
   * nothing about where the contents start. Measured at 1440: 116px for all
   * four once the second line is reserved.
   */
  assert.match(chartCard, /<CardDescription className="min-h-10">/);
});

test("the loading card is exactly as tall as the loaded one", () => {
  // The bar grew from h-1.5 to h-2; a skeleton left behind makes the whole row
  // of five cards resize the moment the first poll lands.
  const bars = statCard.match(/mt-2\.5 h-2/g) ?? [];
  assert.ok(bars.length >= 3, `real bar, skeleton and spacer must agree — found ${bars.length}`);
  assert.doesNotMatch(statCard, /h-1\.5/, "a 1.5 bar survived somewhere");
});

/* -------------------------------------------------------------------------
 * Card chrome
 * ---------------------------------------------------------------------- */

test("every card on the page wears the same ring and shadow", () => {
  /*
   * Found by being asked "do all the cards have the same border?" — they did
   * not. The five metric cards kept Card's default ring-foreground/10 while the
   * six larger ones had been given ring-foreground/[0.07]. A 3% difference
   * nobody would name and everybody sees.
   *
   * Measured after the fix: 11 cards, one distinct computed box-shadow.
   */
  const chrome = fs.readFileSync("lib/theme/card-chrome.js", "utf8");
  assert.match(chrome, /export const PANEL_CARD = "shadow-e1! ring-foreground\/\[0\.07\]"/);

  for (const file of [
    "components/ui/stat-card.jsx",
    "components/dashboard/live-chart-card.jsx",
    "components/dashboard/processes-card.jsx",
    "components/dashboard/server-info-card.jsx",
  ]) {
    const source = fs.readFileSync(file, "utf8");
    assert.match(source, /PANEL_CARD/, `${file} sets its own chrome again`);

    // No card may hand-roll a ring or shadow beside the shared one.
    const code = source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
    const cardTags = code.match(/<Card[^>]*>/gs) ?? [];
    for (const tag of cardTags) {
      assert.doesNotMatch(tag, /ring-foreground\//, `${file} pins its own ring`);
      assert.doesNotMatch(tag, /shadow-(sm|md|lg|e2)\b/, `${file} pins its own shadow`);
    }
  }
});

test("the shadow is forced, because the named utility alone loses", () => {
  /*
   * `Card` hardcodes `shadow-sm`. `shadow-e1` is a different class NAME for the
   * same property, so tailwind-merge keeps both and the stylesheet's emission
   * order decides — it picked `shadow-sm`, so two rounds of "softer shadow"
   * changed nothing and the computed value stayed rgba(0,0,0,0.1) the whole
   * time. `shadow-[var(--shadow-e1)]` does not help either: tailwind-merge's
   * arbitrary-shadow test wants a leading length, so a var() falls through to
   * the shadow-COLOR group and shadow-sm survives again.
   *
   * Measured after forcing it: rgba(16,24,40,0.024) / rgba(16,24,40,0.03),
   * which is --shadow-e1.
   */
  const chrome = fs.readFileSync("lib/theme/card-chrome.js", "utf8");
  assert.match(chrome, /shadow-e1!/, "the ! is gone, so Card's shadow-sm wins again");

  /*
   * Comments stripped first. The file explains the var() attempt in prose, so
   * matching the raw source failed on the very sentence documenting why it does
   * not work — and the obvious response to that is to delete the explanation,
   * which is the one thing here worth keeping.
   */
  const code = chrome.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  assert.doesNotMatch(
    code,
    /shadow-\[var\(/,
    "back to a var() arbitrary value, which tailwind-merge reads as a colour",
  );
  // And the literal must not be inlined — that is the drift this file prevents.
  assert.doesNotMatch(code, /\d+px \d+px/, "the token value has been copied into the class");
});

/* -------------------------------------------------------------------------
 * Translation
 * ---------------------------------------------------------------------- */

test("every new string is translated everywhere", () => {
  const english = messages.en.serverDashboard;

  for (const l of LOCALES) {
    const d = messages[l].serverDashboard;
    assert.ok(d.liveLabel, `${l} is missing liveLabel`);

    for (const key of ["normal", "watch", "high", "off", "unknown"]) {
      assert.ok(d.status?.[key], `${l} is missing status.${key}`);
    }
    for (const key of ["empty", "emptyDetail", "unavailable", "unavailableDetail"]) {
      assert.ok(d.processes?.[key], `${l} is missing processes.${key}`);
    }
    assert.ok(d.charts.noHistoryTitle, `${l} is missing charts.noHistoryTitle`);

    if (l === "en") continue;

    /*
     * Only `watch` is asserted to differ from English. The others legitimately
     * come back identical in several languages — "Normal" is Normal in Spanish,
     * German, French and Portuguese — and the one-voice gate already holds them
     * to the wording this panel ships for the same concept elsewhere.
     */
    assert.notEqual(
      d.status.watch,
      english.status.watch,
      `${l} still carries the English "Watch"`,
    );
  }
});

test("the live heading is the current-value sense of Right now", () => {
  /*
   * "Right now" is on the one-voice exemption list because English uses it for
   * three things: a current value, the soonest scheduling option, and a live
   * traffic column. Japanese had translated the scheduling sense as 今すぐ
   * ("right away"), which is wrong over a block of current readings — so the
   * exemption is being used for what it is for, and this pins the split rather
   * than leaving it to be "tidied" back into one word.
   */
  assert.equal(messages.ja.serverDashboard.liveLabel, "現在");
  assert.notEqual(
    messages.ja.serverDashboard.liveLabel,
    messages.ja.settings.maintenance.reboot.now,
    "the heading took the reboot-scheduling wording",
  );
});
