import test from "node:test";
import assert from "node:assert/strict";

import { PER_PAGE_OPTIONS } from "../lib/schemas/user.js";

/**
 * Choosing 20 rows per page on a list of 15 stopped the list paginating, which
 * hid the whole pagination row — including the selector you would use to go
 * back to 10. The state was only escapable by editing the URL.
 *
 * The rule: the pager follows the page count, the selector follows the list.
 */
const showPager = (meta) => meta.last_page == null || meta.last_page > 1;
const showPerPage = (meta) => meta.total == null || meta.total > PER_PAGE_OPTIONS[0];

const controls = (meta) => ({ pager: showPager(meta), perPage: showPerPage(meta) });

test("15 rows at 20 per page keeps the way back to 10", () => {
  assert.deepEqual(controls({ total: 15, last_page: 1, current_page: 1 }), {
    pager: false,
    perPage: true,
  });
});

test("15 rows at 10 per page shows both", () => {
  assert.deepEqual(controls({ total: 15, last_page: 2, current_page: 1 }), {
    pager: true,
    perPage: true,
  });
});

test("a list that fits on every setting shows neither", () => {
  assert.deepEqual(controls({ total: 3, last_page: 1, current_page: 1 }), {
    pager: false,
    perPage: false,
  });
  // Exactly the smallest option still fits on one page at every setting.
  assert.deepEqual(controls({ total: 10, last_page: 1, current_page: 1 }), {
    pager: false,
    perPage: false,
  });
});

test("11 rows is the first list worth a selector", () => {
  assert.equal(showPerPage({ total: 11 }), true);
});

test("meta that does not say leaves both controls alone", () => {
  assert.deepEqual(controls({ current_page: 1 }), { pager: true, perPage: true });
});
