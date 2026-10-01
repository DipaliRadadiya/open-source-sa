import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { hasShellComment } from "../lib/schemas/cronjob.js";
import { randomPassword } from "../lib/databases/random.js";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

// Bug list openclawbug.23-172-120-79, frontend part (1 Oct).

test("a port range needs TCP or UDP; a single port can be both", () => {
  // The schema imports through the @/ alias, which node --test cannot resolve,
  // so this checks the rule itself: a real range (to ≠ from) with "all".
  const src = read("lib/schemas/firewall.js");
  assert.match(src, /parsed\.to && parsed\.to !== parsed\.from && values\.protocol === "all"/);
  assert.match(src, /path: \["protocol"\], message: "rangeNeedsProtocol"/);
  assert.match(read("components/firewall/add-rule-dialog.jsx"), /"rangeNeedsProtocol",/);
});

test("a cron command with a # note is caught; # inside quotes, URLs and $# is not", () => {
  for (const c of ["echo hello # nightly note", "# only a note", "echo hi;# x", "a && #b", "x|#y"]) assert.equal(hasShellComment(c), true, c);
  for (const c of ['echo "#tag"', "echo '# x'", "curl https://x.y/#frag", "echo $#", "echo a\\ #b", "php artisan schedule:run"]) assert.equal(hasShellComment(c), false, c);
});

test("generated database passwords never contain =", () => {
  for (let i = 0; i < 2000; i += 1) assert.doesNotMatch(randomPassword(), /=/);
});

test("the ban dialog refuses the server's own address", () => {
  const src = read("components/fail2ban/ban-ip-dialog.jsx");
  assert.match(src, /if \(serverIp && ip\.trim\(\) === serverIp\) \{\n\s*setError\(t\("ban\.ownServer"\)\);/);
  assert.match(read("app/(app)/fail2ban/page.jsx"), /getServerCapabilities\(\)/);
});

test("server sync reasons wrap and the type moves under the name on phones", () => {
  const src = read("components/sync/sync-results.jsx");
  assert.match(src, /<TableCell className="align-top whitespace-normal">/);
  assert.match(src, /<TableHead className="hidden w-\[16%\] sm:table-cell">/);
});

test("a new backup setup for a site with no database starts on Files only", () => {
  assert.match(read("components/backups/backup-settings-fields.jsx"), /noDatabase === true && !target && !typePicked && type === "full"/);
});
