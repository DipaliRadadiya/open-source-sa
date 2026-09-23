import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { safeNext } from "../lib/auth/safe-next.js";

const read = (p) => fs.readFileSync(p, "utf8");

test("Back clears the filter instead of leaving the screen", () => {
  /*
   * Typing replaced the URL on every keystroke — correct, or "moodle x" leaves
   * eight history entries and Back walks the reader out of their own search
   * one letter at a time. But replacing the FIRST one too meant the unfiltered
   * list never entered history, so Back from a filtered table landed on
   * /dashboard. Driven: /applications -> type -> Back -> /dashboard.
   *
   * One push at the empty -> set boundary gives Back exactly one job.
   */
  for (const f of ["components/data-table/nav-transition.jsx", "hooks/use-set-query.js"]) {
    const src = read(f);
    assert.match(src, /const hadQuery = searchParams\.toString\(\) !== "";/, f);
    assert.match(src, /const navigate = hadQuery \|\| !qs \? router\.replace : router\.push;/, f);
    assert.doesNotMatch(src, /startTransition\(\(\) =>\s*router\.replace\(/, `${f} still always replaces`);
  }
});

test("signing in returns you to the screen the session died on", () => {
  /*
   * The server cannot answer this. A page here sees only host / user-agent /
   * accept / x-forwarded-* — checked by dumping the real header list from a
   * build, because I had guessed at `x-invoke-path` and it does not exist.
   * So the browser records it and the login form spends it.
   *
   * Driven end to end against a build: read a filtered list, expire the
   * session, get thrown to /login, sign in, land back on
   * `/applications?search=moodle` — query string intact, because a filtered
   * list is a different screen from the bare route.
   */
  const recorder = read("components/remember-path.jsx");
  assert.match(recorder, /usePathname/);
  assert.match(recorder, /rememberPath\(query \? `\$\{pathname\}\?\$\{query\}` : pathname\)/,
    "the query is part of where you were");

  // Mounted in the signed-in shells only, so a signed-out screen never records
  // itself as somewhere to return to.
  for (const shell of ["app/(app)/layout.jsx", "app/admin/layout.jsx"]) {
    assert.match(read(shell), /<RememberPath \/>/, shell);
  }

  const form = read("components/forms/login-form.jsx");
  assert.match(
    form,
    /router\.push\(safeNext\(searchParams\.get\("next"\)\) \?\? takeRememberedPath\(\) \?\? "\/"\)/,
    "explicit ?next= wins, then the recorded path, then let the server decide",
  );

  // Deliberate sign-out is not being thrown out: the next person to use this
  // browser must not land on the last one's screen.
  assert.match(read("components/sections/user-menu.jsx"), /forgetRememberedPath\(\)/);
});

test("a recorded path is re-checked, because anything on the origin can write it", () => {
  // sessionStorage is not a trust boundary. Each of these was actually put in
  // the key and signed in with; every one landed on /dashboard.
  for (const evil of [
    "https://evil.example.com/",
    "//evil.example.com/x",
    "javascript:alert(1)",
    "/login?x=1",
    "/register",
    "",
    null,
  ]) {
    assert.equal(safeNext(evil), null, `must refuse ${JSON.stringify(evil)}`);
  }
  assert.equal(safeNext("/applications?search=moodle"), "/applications?search=moodle");

  const store = read("lib/auth/last-path.js");
  assert.match(store, /if \(!safeNext\(path\)\) return;/, "checked on the way in too");
  assert.match(store, /sessionStorage\.removeItem\(LAST_PATH_KEY\);\s+return safeNext\(value\);/,
    "and spent once on the way out");
});

test("next= cannot be turned into an open redirect", () => {
  assert.equal(safeNext("/applications/40/backups"), "/applications/40/backups");
  assert.equal(safeNext("/php"), "/php");

  // Off-site, in every shape a browser would follow.
  assert.equal(safeNext("https://evil.test"), null);
  assert.equal(safeNext("//evil.test"), null, "protocol-relative leaves the site");
  assert.equal(safeNext("http://evil.test/x"), null);
  assert.equal(safeNext("javascript:alert(1)"), null);
  assert.equal(safeNext("evil.test"), null);

  // Signing in and landing back on the login form is a loop.
  assert.equal(safeNext("/login"), null);
  assert.equal(safeNext("/register"), null);
  assert.equal(safeNext("/setup"), null);

  assert.equal(safeNext(null), null);
  assert.equal(safeNext(undefined), null);
  assert.equal(safeNext(42), null);
});

test("the pure helper stays importable from the browser", () => {
  /*
   * It lives alone precisely because `signed-out-path.js` reads `next/headers`,
   * which cannot be bundled for the client. Importing the server file from the
   * login form is what broke the build the first time.
   */
  assert.doesNotMatch(read("lib/auth/safe-next.js"), /^import /m);
});
