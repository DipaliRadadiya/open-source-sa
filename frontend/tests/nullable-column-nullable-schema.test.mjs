import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import {
  restoreSchema,
  restoresResponseSchema,
} from "../lib/schemas/backup.js";
import { applicationSchema } from "../lib/schemas/application.js";
import { cronjobSchema } from "../lib/schemas/cronjob.js";

/*
 * Krishna, on the Restores tab: "why getting this error?" — and once the box
 * learned to name its failures, it answered: "The panel could not read this.
 * The server's answer was not the shape this page expects."
 *
 * It was `backup_id: z.number()`. `restores.backup_id` is
 * `nullable()->nullOnDelete()`, so deleting a single backup sets it to null on
 * every restore that used it. Zod then rejected the WHOLE response — not the
 * row, the response — and the entire Restores tab went red. One deleted
 * backup, no restore history at all.
 *
 * Neither `backup_id` nor `slug` is rendered anywhere. They were required for
 * no reason, and being required was the only thing they did.
 *
 * The class: a frontend schema must accept everything the API CAN send, and
 * the API sends whatever the column holds. Found two more by cross-checking
 * every required field against the migrations.
 */

const read = (p) => fs.readFileSync(p, "utf8");

test("a restore survives the backup it came from being deleted", () => {
  const row = {
    id: 7,
    backup_id: null,
    application_id: 3,
    type: "full",
    status: "succeeded",
  };
  assert.equal(restoreSchema.safeParse(row).success, true);

  // And the whole response with it — this is what actually broke. A single
  // orphaned row took the page down, not just its own line.
  const response = restoresResponseSchema.safeParse({
    restores: [
      { id: 1, backup_id: 12, application_id: 3, type: "full", status: "succeeded" },
      row,
    ],
    meta: { current_page: 1, per_page: 20, total: 2, last_page: 1 },
  });
  assert.equal(response.success, true, "one orphan must not reject the list");
  assert.equal(response.data.restores.length, 2);
});

test("a site with no domain does not reject the applications list", () => {
  /*
   * `applications.domain` became nullable when domains moved to their own
   * table, and `ApplicationResource` passes it straight through. One such site
   * would have taken out the list, the dashboard's site-health read and every
   * screen that loads a site.
   */
  const parsed = applicationSchema.safeParse({
    id: 1,
    name: "staging copy",
    domain: null,
    site_type: "php",
    status: "active",
  });
  assert.equal(parsed.success, true);
});

test("a cron job with no slug does not reject the list", () => {
  // Nullable in the table, raw in the resource.
  const parsed = cronjobSchema.safeParse({
    id: 1,
    name: "nightly",
    slug: null,
    command: "php artisan schedule:run",
    expression: "* * * * *",
    username: "site",
    active: true,
  });
  assert.equal(parsed.success, true);
});

test("the three fields are declared nullable, not merely tolerated", () => {
  // `.passthrough()` on restoreSchema means an undeclared field would sail
  // through — so the assertions above could pass for the wrong reason if the
  // key were simply removed. These pin that the key is still declared.
  assert.match(read("lib/schemas/backup.js"), /backup_id: z\.number\(\)\.nullish\(\)/);
  assert.match(read("lib/schemas/application.js"), /domain: z\.string\(\)\.nullish\(\)/);
  assert.match(read("lib/schemas/cronjob.js"), /slug: z\.string\(\)\.nullish\(\)/);
});
