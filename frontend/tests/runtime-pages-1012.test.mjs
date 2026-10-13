import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const LOCALES = ["en", "es", "de", "fr", "pt", "ja", "ru", "hi"];

// PHP + Node.js redesign, 12 Oct (Krishna): one table of installed versions instead of
// a version picker, php.ini as a tab beside Extensions and ionCube.

test("both pages list every version in one table with Install in its header", () => {
  for (const page of ["app/(app)/php/page.jsx", "app/(app)/node/page.jsx"]) {
    const src = read(page);
    assert.match(src, /<VersionsCard/, page);
    assert.match(src, /action=\{install\}/, page);
    assert.match(src, /footnote=\{t\("versions\.defaultHint"\)\}/, page);
    assert.doesNotMatch(src, /VersionBar|PhpVersionList/, `${page} brought back the chips or the two-pane list`);
  }
});

test("the PHP settings heading carries a version switcher, so a long table is no trip back up", () => {
  // 12 Oct: the two-pane split looked bad (Krishna); one column, switch beside the settings.
  const php = read("app/(app)/php/page.jsx");
  assert.match(php, /\{versions\.length > 1 \? <SettingsVersionPicker versions=\{ordered\} selected=\{selected\} \/> : null\}/);
  const picker = read("components/php/settings-version-picker.jsx");
  assert.match(picker, /if \(!guardNavigation\(href\)\) router\.push\(href, \{ scroll: false \}\);/);
  assert.match(picker, /params\.set\("version", version\);/);
});

test("the column template is not imported from a client module into a server page", () => {
  // Calling an export of a "use client" file on the server throws at request time,
  // although the build passes.
  const lib = read("lib/runtime/version-rows.js");
  assert.doesNotMatch(lib, /use client/);
  for (const page of ["app/(app)/php/page.jsx", "app/(app)/node/page.jsx"]) {
    assert.match(read(page), /import \{ rowGrid \} from "@\/lib\/runtime\/version-rows"/, page);
  }
  assert.doesNotMatch(read("components/runtime/versions-table.jsx"), /export function rowGrid/);
});

test("header and rows share one grid, so Actions fits its buttons in every language", () => {
  // Separate grids per row put the header at a different x than the cells, and fixed
  // fractions wrapped "Als Standard festlegen | Entfernen" onto two lines at 1280px.
  const lib = read("lib/runtime/version-rows.js");
  assert.match(lib, /export const SUBGRID = "@3xl\/versions:col-span-full @3xl\/versions:grid @3xl\/versions:grid-cols-subgrid";/);
  assert.equal((lib.match(/_fit-content\(20rem\)\]"/g) ?? []).length, 4, "every template caps Actions at 20rem");
  for (const row of ["components/php/version-summary.jsx", "components/node/version-summary.jsx"]) {
    assert.match(read(row), /<div className=\{cn\("grid gap-y-2 @3xl\/versions:items-center", SUBGRID\)\}>/, row);
  }
  for (const row of ["components/php/version-summary.jsx", "components/node/version-summary.jsx"]) {
    assert.match(read(row), /<RuntimeStatusBadge version=\{version\} namespace="(php|node)" className=\{WRAP\} \/>/, row);
  }
});

test("no support column when the release schedule could not be read", () => {
  const lib = read("lib/runtime/version-rows.js");
  assert.match(lib, /export function rowGrid\(runtime, withSupport\)/);
  assert.match(read("app/(app)/node/page.jsx"), /\.\.\.\(lifecycleAvailable \? \[t\("table\.colSupport"\)\] : \[\]\)/);
  assert.match(read("components/php/version-summary.jsx"), /\{lifecycleAvailable \? \(\s*<SupportCell/);
  assert.match(read("components/node/version-summary.jsx"), /\{lifecycleAvailable \? \(\s*<SupportCell/);
});

test("a PHP row selects its version, keeps the open tab, and asks before dropping a php.ini edit", () => {
  const row = read("components/php/version-summary.jsx");
  assert.match(row, /const params = new URLSearchParams\(searchParams\);\s*params\.set\("version", version\.version\);/);
  assert.match(row, /scroll=\{false\}/);
  assert.match(row, /if \(guardNavigation\(href\)\) event\.preventDefault\(\);/);
  assert.match(row, /after:absolute after:inset-0/);
  // The controls sit above the row link, and clicks between them fall through to it.
  assert.match(row, /pointer-events-none relative z-10 [^"]*\[&>\*\]:pointer-events-auto/);
});

test("php.ini is a tab that keeps its edit while you look at another tab", () => {
  const tabs = read("components/php/version-tabs.jsx");
  assert.match(tabs, /const VALUES = \["extensions", "ioncube", "ini"\];/);
  assert.match(tabs, /section\.keep && opened\.has\(section\.value\)/);
  assert.match(tabs, /forceMount/);
  const page = read("app/(app)/php/page.jsx");
  assert.match(page, /ini=\{<IniEditor key=\{selected\} version=\{selected\} canManage=\{canManage\} \/>\}/);
  const ini = read("components/php/ini-editor.jsx");
  assert.match(ini, /useWatchUnsaved\(`php-ini-\$\{version\}`, dirty\);/);
  // The acknowledgement still gates Save, and only shows once there is an edit.
  assert.match(ini, /: !acknowledged\s*\? t\("phpIni\.blockedAcknowledge"\)/);
  assert.match(ini, /\) : dirty \? \(/);
});

test("an up-to-date npm is said, not offered as a dead button", () => {
  const row = read("components/node/version-summary.jsx");
  assert.match(row, /\{npmCurrent \? \(\s*<span className="text-xs text-muted-foreground">\{t\("table\.npmCurrent"\)\}<\/span>/);
});

test("every locale has the new labels, and the help text speaks to users", () => {
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    for (const key of ["title", "description", "colVersion", "colSupport", "colUsedBy", "colActions", "usedByCount", "usedByNone", "usedByTitle"]) {
      assert.ok(m.php.table?.[key], `${locale} php.table.${key}`);
      assert.ok(m.node.table?.[key], `${locale} node.table.${key}`);
    }
    assert.ok(m.node.table.colNpm && m.node.table.npmCurrent, `${locale} node npm labels`);
    assert.ok(m.php.settings?.title?.includes("{version}"), `${locale} php.settings.title`);
    assert.ok(m.php.tabs?.ini, `${locale} php.tabs.ini`);
    for (const key of ["description", "perApp", "perAppLink", "retry", "discard"]) {
      assert.ok(m.php.ini?.[key], `${locale} php.ini.${key}`);
    }
    assert.match(m.php.ioncube.hint, /\{version\}/, `${locale} ioncube hint names the version`);
    assert.doesNotMatch(m.php.ioncube.hint, /apt/, `${locale} ioncube hint still explains packaging`);
    assert.equal(m.php.versions.switchLabel, undefined, `${locale} keeps the dead "Settings for:" label`);
  }
});

test("on a phone, picking a version brings its settings into view (only when off screen)", () => {
  const row = read("components/php/version-summary.jsx");
  assert.match(row, /if \(guardNavigation\(href\)\) event\.preventDefault\(\);\s*else requestReveal\(version\.version\);/);
  const reveal = read("components/php/reveal-settings.jsx");
  assert.match(reveal, /getBoundingClientRect\(\)\.top > window\.innerHeight \* 0\.7/);
  assert.match(read("app/(app)/php/page.jsx"), /<RevealSettings version=\{selected\} targetId="php-settings-title" \/>/);
});

test("extensions flow into columns, each switch beside its own name", () => {
  // One stretched row left a wide gap between the name and its switch (Krishna, 12 Oct);
  // grid-cols-1, not an implicit track, or a long description widens the phone row past the card.
  const card = read("components/php/extensions-card.jsx");
  assert.match(card, /<Card className="@container\/ext">/);
  assert.match(card, /grid grid-cols-1 @xl\/ext:grid-cols-2 @6xl\/ext:grid-cols-3/);
  assert.doesNotMatch(card, /<Table>/);
  // ionCube's card is a container, so its button moves to the right on a wide card.
  assert.match(read("components/php/ioncube-card.jsx"), /<Card className="@container gap-0 py-0">/);
});
