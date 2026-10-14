import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

// Krishna, 13 Oct: table headers too small and cramped; skeletons not in the page's shape;
// the icon-only button on Automatic cleanup needs a tooltip.
const read = (p) => fs.readFileSync(p, "utf8");

test("table headers are 44px tall and typed the same everywhere", () => {
  assert.match(read("components/ui/table.jsx"), /"h-11 px-4 text-left align-middle text-sm font-medium whitespace-nowrap/);
  // Steel UI (Krishna, 14 Oct: "keep 12px uppercase; every table the same"): hand-built
  // header rows take the shared header type through data-slot, not their own classes.
  for (const file of ["components/runtime/versions-table.jsx", "components/applications/domains/domains-section.jsx"]) {
    assert.match(read(file), /aria-hidden\s+data-slot="table-head"/, file);
  }
  const css = read("app/globals.css");
  assert.match(css, /\[data-slot="table-head"\],[\s\S]{0,240}font-size: 12px;[\s\S]{0,60}text-transform: uppercase;/);
  const db = read("components/databases/database-tables.jsx");
  assert.match(db, /text-left text-sm text-muted-foreground/);
  assert.match(db, /"h-11 px-3 font-medium sm:px-5"/);
  for (const file of ["components/ui/table.jsx", "components/runtime/versions-table.jsx", "components/applications/domains/domains-section.jsx", "components/databases/database-tables.jsx"]) {
    assert.doesNotMatch(read(file), /text-\[13px\]/, file);
  }
});

test("a header can wrap on its own without wrapping its cells", () => {
  assert.match(read("components/ui/data-table.jsx"), /header\.column\.columnDef\.meta\?\.headClassName/);
  // fr/ru at 1280px: these two headers were the only thing making the table scroll.
  const users = read("components/admin/users/users-table.jsx");
  assert.equal((users.match(/meta: \{ headClassName: HEAD_WRAPS \}/g) ?? []).length, 2);
});

test("the Automatic cleanup icon button names itself on hover", () => {
  const card = read("components/disk-cleaner/schedule-card.jsx");
  assert.match(card, /<TooltipTrigger asChild>\{configureButton\}<\/TooltipTrigger>\s*<TooltipContent>\{t\("schedule\.setUp"\)\}<\/TooltipContent>/);
  assert.match(card, /aria-label=\{t\("schedule\.setUp"\)\}/);
});

test("skeletons follow the redesigned pages", () => {
  assert.match(read("app/(app)/disk-cleaner/loading.jsx"), /<SkCompactTile action \/>/);
  assert.equal((read("app/(app)/services/loading.jsx").match(/<SkCompactTile \/>/g) ?? []).length, 4);
  assert.match(read("app/(app)/php/loading.jsx"), /<VersionsCardSkeleton rows=\{4\} columns=\{4\} \/>/);
  assert.match(read("app/(app)/node/loading.jsx"), /<VersionsCardSkeleton rows=\{3\} columns=\{5\} \/>/);
  // The settings card head has an icon chip since 12 Oct.
  assert.match(read("components/settings/settings-skeleton.jsx"), /<Skeleton className="size-9 shrink-0 rounded-xl" \/>/);
});
