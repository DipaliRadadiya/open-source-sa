import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import { createRequire } from "node:module";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";

const require = createRequire(import.meta.url);
const { transformSync } = require("next/dist/build/swc");
const { clsx } = require("clsx");
const { twMerge } = require("tailwind-merge");
const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const messages = JSON.parse(read("messages/en.json")).services;
const lookup = (key) => key.split(".").reduce((value, part) => value?.[part], messages);
const t = (key) => lookup(key) ?? key;
t.has = (key) => typeof lookup(key) === "string";
const cn = (...values) => twMerge(clsx(values));
const icons = Object.fromEntries(["CircleCheck", "CircleMinus", "CircleAlert", "CircleHelp", "Loader2"].map((name) => [name, (props) => React.createElement("svg", { ...props, "data-test-icon": name })]));

// Compile the real JSX and shared Badge/CVA; only external hooks/icons and the
// DataTable boundary are replaced. These are rendered unit fixtures, not live mutations.
function load(path, replacements = {}) {
  const code = transformSync(read(path), {
    filename: path,
    jsc: { parser: { syntax: "ecmascript", jsx: true }, target: "es2022", transform: { react: { runtime: "automatic" } } },
    module: { type: "commonjs" },
  }).code;
  const loadedModule = { exports: {} };
  vm.runInNewContext(code, {
    module: loadedModule,
    exports: loadedModule.exports,
    require: (name) => replacements[name] ?? require(name),
  }, { filename: path });
  return loadedModule.exports;
}
const { Badge } = load("components/ui/badge.jsx", { "@/lib/utils": { cn } });
const { ServiceStatusBadge } = load("components/services/service-status-badge.jsx", {
  "next-intl": { useTranslations: () => t },
  "lucide-react": icons,
  "@/components/ui/badge": { Badge },
});
const html = (props) => renderToStaticMarkup(React.createElement(ServiceStatusBadge, props));
const opening = (value) => value.slice(0, value.indexOf(">") + 1);

const { ServiceVersion } = load("components/services/service-version.jsx", { "next-intl": { useTranslations: () => t } });

let captured;
const { ServicesTable } = load("components/services/services-table.jsx", {
  "@/components/ui/app-link": { __esModule: true, default: ({ children, ...props }) => React.createElement("a", props, children) },
  "next-intl": { useTranslations: () => t, useFormatter: () => ({ number: (value, options) => new Intl.NumberFormat("en", options).format(value) }) },
  "@/lib/utils": { cn },
  "@/components/ui/data-table": { DataTable: (props) => { captured = props; return React.createElement("div"); } },
  "@/components/services/service-actions": { ServiceActions: () => null },
  "@/components/services/service-boot-switch": { ServiceBootSwitch: () => null },
  "@/components/services/service-status-badge": { ServiceStatusBadge },
  "@/components/services/service-version": { ServiceVersion },
  "@/lib/services/install-home": { installHome: () => ({ href: "/php", retryLabel: "retryPhp" }) },
});
function tableProps() {
  renderToStaticMarkup(React.createElement(ServicesTable, { data: [], busy: {}, setRowBusy: () => {}, canManage: false }));
  return captured;
}
function cell(id, service) {
  const props = tableProps();
  const column = props.columns.find((value) => (value.id ?? value.accessorKey) === id);
  return renderToStaticMarkup(React.createElement(column.cell, { row: { original: service }, table: { options: { meta: props.meta } } }));
}

test("service states inherit the exact shared badge geometry, typography and colors", () => {
  for (const [status, variant, icon] of [["active", "success", "CircleCheck"], ["inactive", "muted", "CircleMinus"], ["failed", "destructive", "CircleAlert"]]) {
    const result = html({ status });
    assert.equal(opening(result), opening(renderToStaticMarkup(React.createElement(Badge, { variant }, "reference"))));
    assert.match(result, new RegExp(`data-test-icon="${icon}"`));
    assert.ok(result.includes(t(`status.${status}`)));
  }
  assert.doesNotMatch(read("components/services/service-status-badge.jsx"), /<Badge[^>]*className=/);
});

test("recognized pending actions win over stale status without showing success", () => {
  for (const action of Object.keys(messages.busy)) {
    const result = html({ status: "active", state: "installing", busyAction: action });
    assert.match(result, /data-variant="muted"/);
    assert.match(result, /data-test-icon="Loader2"/);
    assert.match(result, /animate-spin/);
    assert.ok(result.includes(t(`busy.${action}`)));
  }
  assert.match(html({ status: "active", busyAction: "not-an-action" }), /data-variant="success"/);
});

test("installing, failed install and unknown status retain truthful words and icons", () => {
  const installing = html({ status: "inactive", state: "installing" });
  assert.match(installing, /data-variant="muted"/);
  assert.match(installing, /data-test-icon="Loader2"/);
  assert.ok(installing.includes(t("state.installing")));
  assert.ok(!installing.includes(t("status.inactive")));
  assert.match(html({ status: "failed", state: "install_failed" }), /data-variant="destructive"/);
  const unknown = html({ status: "activating" });
  assert.match(unknown, /data-variant="muted"/);
  assert.match(unknown, /data-test-icon="CircleHelp"/);
  assert.ok(unknown.includes("activating"));
  assert.ok(html({ status: "<script>unsafe</script>" }).includes("&lt;script&gt;"));
});

test("the six column shares total 100 and keep the existing order", () => {
  const props = tableProps();
  assert.deepEqual(Array.from(props.columns, (value) => value.id ?? value.accessorKey), ["label", "status", "memory", "cpu", "boot", "actions"]);
  const shares = Array.from(props.columns, (value) => Number(value.meta.className.match(/w-\[(\d+)%\]/)[1]));
  assert.deepEqual(shares, [15, 22, 12, 10, 16, 25]);
  // At the existing 900px breakpoint, leave room for the measured 160px
  // German pending pill without overriding shared typography or truncating it.
  assert.ok(900 * shares[1] / 100 - 32 >= 160);
  assert.equal(shares.reduce((sum, value) => sum + value, 0), 100);
  assert.equal(props.fixedLayout, true);
  assert.equal(props.bare, true);
});

test("column classes align both headers and cells without extra CPU padding", () => {
  const columns = tableProps().columns;
  for (const id of ["status", "boot"]) assert.match(columns.find((value) => (value.id ?? value.accessorKey) === id).meta.className, /text-center/);
  for (const id of ["memory", "cpu", "actions"]) {
    const column = columns.find((value) => value.id === id);
    assert.match(column.meta.className, /text-right/);
    assert.match(renderToStaticMarkup(column.header()), /text-right/);
  }
  assert.doesNotMatch(columns.find((value) => value.id === "cpu").meta.className, /pr-8/);
  // The reserved spinner slot must not offset the actual boot switch. Protected
  // text has no spinner slot, so it must not receive a balancing spacer.
  assert.match(cell("boot", { key: "test", protected: false }), /w-6 shrink-0/);
  assert.doesNotMatch(cell("boot", { key: "test", protected: true }), /w-6 shrink-0/);
  const dataTable = read("components/ui/data-table.jsx");
  assert.match(dataTable, /header\.column\.columnDef\.meta\?\.className/);
  assert.match(dataTable, /cell\.column\.columnDef\.meta\?\.className/);
});

test("long names can wrap while units and retry information remain intact", () => {
  const name = "VeryLongServiceNameWithoutBreaks".repeat(4);
  const installed = cell("label", { key: "php", label: name, unit: "php8.4-fpm", state: "installed" });
  assert.match(installed, /font-medium whitespace-normal wrap-anywhere/);
  assert.ok(installed.includes(name));
  // The unit line wraps before the version (27 Oct) rather than cutting "redis-server · 7.0.15" off.
  assert.match(installed, /font-mono text-xs whitespace-normal wrap-anywhere/);
  assert.ok(installed.includes("php8.4-fpm"));
  const versioned = cell("label", { key: "redis", label: "Redis", unit: "redis-server", state: "installed", version: "7.0.15", package_version: "5:7.0.15-1ubuntu0.24.04.5" });
  // The space before the span is the break point; the version itself never splits.
  assert.match(versioned, /redis-server <span class="whitespace-nowrap tabular-nums" title="[^"]+">· (<!-- -->)?7\.0\.15<\/span>/);
  assert.ok(!cell("label", { key: "postfix", label: "Postfix", unit: "postfix", state: "installed", version: null }).includes("·"));
  const failed = cell("label", { key: "php", label: "PHP", state: "install_failed", install_reason: "package", install_message: "Package missing", retryable: true });
  assert.ok(failed.includes("Package missing"));
  assert.match(failed, /href="\/php"/);
});

test("null usage stays unknown, zero stays zero and CPU uses locale formatting", () => {
  assert.ok(cell("memory", { usage: null }).includes("—"));
  assert.ok(cell("memory", { usage: { memory_human: "0 B" } }).includes("0 B"));
  assert.ok(cell("cpu", { usage: null }).includes("—"));
  assert.ok(cell("cpu", { usage: { cpu_percent: 0 } }).includes("0.0%"));
  assert.ok(cell("cpu", { usage: { cpu_percent: 100 } }).includes("100.0%"));
});

test("table density, failed-unit signal and responsive card boundary stay unchanged", () => {
  const props = tableProps();
  assert.match(props.rowClassName({ status: "active", state: "installed" }), /py-2/);
  assert.match(props.rowClassName({ status: "failed", state: "installed" }), /bg-destructive/);
  assert.doesNotMatch(props.rowClassName({ status: "failed", state: "install_failed" }), /bg-destructive/);
  assert.match(read("components/services/services-panel.jsx"), /@min-\[900px\]\/svc:block/);
});

test("the top of the page is the shared status tiles, coloured only when something is wrong", () => {
  const panel = read("components/services/services-panel.jsx");
  assert.match(panel, /import \{ Tile \} from "@\/components\/applications\/app-status-tiles"/);
  assert.match(panel, /tone=\{stopped > 0 \? "warning" : "neutral"\}/);
  assert.match(panel, /tone=\{attention\.length > 0 \? "destructive" : "neutral"\}/);
  // Refresh lives in the Installed services header (Krishna, 12 Oct), never in a tile.
  assert.match(panel, /title=\{t\("sections\.running\.title"\)\}\s*hint=\{t\("sections\.running\.hint"\)\}\s*action=\{<RefreshButton \/>\}/);
  assert.equal((panel.match(/<RefreshButton/g) ?? []).length, 3, "running + the two fallbacks");
  assert.doesNotMatch(panel.slice(panel.indexOf("tiles.checked"), panel.indexOf("tiles.checked") + 300), /RefreshButton/);
  assert.equal((panel.match(/<Tile\n\s*compact\n/g) ?? []).length, 4);
  assert.doesNotMatch(panel, /summary\.allRunning|t\("checkedAt"/);
  const tiles = read("components/applications/app-status-tiles.jsx");
  assert.match(tiles, /export function Tile\(/);
  for (const locale of ["en", "es", "de", "fr", "pt", "ja", "ru", "hi"]) {
    const s = JSON.parse(read(`messages/${locale}.json`)).services;
    for (const key of ["running", "runningValue", "stopped", "attention", "count", "none", "checked"]) {
      assert.ok(s.tiles?.[key], `${locale} tiles.${key}`);
    }
    assert.equal(s.checkedAt, undefined, `${locale} old checkedAt removed`);
  }
});
