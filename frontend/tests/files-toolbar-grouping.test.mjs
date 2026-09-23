import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported: "make this ui proper. currently it looks messy and unstructured."
 *
 * Eight controls sat in one flat row, all the same weight, doing four
 * unrelated jobs — looking at the folder, adding to it, repairing it, leaving
 * it. Upload, the only primary action, sat sixth and looked exactly like
 * Trash. Nothing contained them, so they floated on the page over two rows.
 *
 * See memory/research-files-toolbar.md for the cPanel/Plesk/CloudPanel survey
 * behind the grouping.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const panel = read("components/applications/files/files-panel.jsx");
const fix = read("components/applications/files/fix-permissions-button.jsx");

test("the controls sit on one surface, with search inside it", () => {
  assert.match(panel, /@container\/toolbar flex flex-col gap-3 rounded-xl border bg-muted\/30 p-2/);
  // Search used to own a row of its own to hold one input.
  assert.match(panel, /<LocalSearchInput/);
  assert.match(panel, /className="sm:max-w-56"/);
});

test("every control in the strip still looks like a control", () => {
  /*
   * Reported after the first pass: "some buttons not looks likes even button
   * or links." Storage, Hide hidden files, Fix permissions and Trash had been
   * dropped to `ghost` to rank them below the create buttons — but a ghost
   * control has no border and no fill, and on this toolbar's tinted strip that
   * leaves it reading as a caption rather than something pressable.
   *
   * Rank is carried by COLOUR instead: one filled primary, the rest outlined,
   * the ones that do not add anything in muted text. Nothing is ranked by
   * having its surface taken away.
   */
  const strip = panel.slice(panel.indexOf("@container/toolbar"));
  assert.doesNotMatch(strip, /variant="ghost"/);
  assert.doesNotMatch(fix, /variant="ghost"/);
  assert.match(fix, /variant="outline"/);

  // Upload is the only control with no `variant` — the filled default.
  assert.match(panel, /<Button size="sm" disabled=\{!canWrite\} onClick=\{\(\) => setUploadOpen\(true\)\}>/);
  // …and the only one. A second filled button would make "the blue one" stop
  // meaning anything.
  assert.equal(panel.match(/<Button size="sm"/g)?.length ?? 0, 1);

  /*
   * Rank no longer comes from greying the lesser controls: grey TEXT on this
   * tinted strip read as disabled ("storage and hide hidden files button looks
   * like disabled"). Their text is full strength; only the icon is muted.
   */
  const storage = read("components/applications/files/size-breakdown-sheet.jsx");
  for (const [name, src] of [["panel", strip], ["fix permissions", fix], ["storage", storage]]) {
    assert.doesNotMatch(src, /<Button[^>]*className="text-muted-foreground"/, `${name}: a control's text must not be greyed`);
  }
  // …and not the icon either: a grey icon beside dark text read as a bug next
  // to New folder / New file, which are plain outline buttons.
  for (const [name, src] of [["panel", strip], ["fix permissions", fix], ["storage", storage]]) {
    assert.doesNotMatch(src, /\[&_svg\]:text-muted-foreground/, `${name}: icons match the other toolbar buttons`);
  }

  /*
   * Trash is a light-red TINT (Krishna, 2026-09-23) — outline with a 5% fill
   * and red text, never the solid `destructive` variant. Solid red stays
   * reserved for the controls that actually delete.
   */
  const trash = panel.slice(panel.indexOf("files?trash=1") - 600, panel.indexOf("files?trash=1"));
  assert.match(trash, /bg-destructive\/5 text-destructive/);
  assert.doesNotMatch(trash, /variant="destructive"/);
});

test("nothing was hidden behind a menu to tidy the row", () => {
  /*
   * cPanel's rule is grey-don't-hide, and folding Trash away costs a click on
   * something used daily. The mess was the lack of grouping, not the count —
   * collapsing controls would have "fixed" the screenshot and made the page
   * worse.
   */
  for (const label of ["newFolder.action", "newFile.action", "uploadDialog.action", "trash.action"]) {
    assert.ok(panel.includes(label), `${label} should still be a visible button`);
  }
  assert.doesNotMatch(panel, /DropdownMenu[^]*newFolder\.action/);
});

test("the group divider is gated on a measured container width", () => {
  /*
   * 73rem is measured. The groups stop fitting on one line at a strip width of
   * 1176px and a container query measures the CONTENT box, 18px less than the
   * border box. 72rem showed the rule at 1176 where its own 17px then caused
   * the wrap it exists to avoid; 74rem held it back to 1216 and lost it on a
   * good single row at 1196.
   *
   * A viewport breakpoint cannot do this job: the strip is narrower than the
   * window by whatever the sidebar takes.
   */
  assert.match(panel, /hidden @\[73rem\]\/toolbar:block/);
  assert.doesNotMatch(panel, /@\[7[024]rem\]\/toolbar:block/);
  // The second rule sits mid-row and is safe at any width.
  assert.match(panel, /<Separator orientation="vertical" className="mx-0\.5 !h-5 !self-center" \/>/);
});

test("the rules are centred against the buttons, not floated to the top", () => {
  /*
   * Reported: "vertical line between is no align properly." Measured — both
   * rules sat at centre 85 while every button sat at 91.
   *
   * The primitive carries `data-vertical:self-stretch`, and an attribute
   * selector outranks the row's `items-center`: the rule stretched to the line
   * box and was then clamped to 20px by `!h-5`, which pins it to the TOP.
   * Nothing about the markup looked wrong, which is why this is pinned by a
   * rule rather than by eye.
   */
  const separators = panel.match(/className="mx-0\.5 !h-5[^"]*"/g) ?? [];
  assert.equal(separators.length, 2, "both toolbar rules should be found");
  for (const cls of separators) {
    assert.ok(cls.includes("!self-center"), `separator missing !self-center: ${cls}`);
  }
  // Still stretching for every other use — this override is local on purpose.
  assert.match(
    read("components/ui/separator.jsx"),
    /data-vertical:self-stretch/,
    "the shared primitive should be left alone",
  );
});
