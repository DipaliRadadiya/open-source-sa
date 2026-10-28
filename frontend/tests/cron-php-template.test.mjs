import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { resolveTemplate } from "../lib/cron-jobs/resolve-template.js";
import { applicationSchema } from "../lib/schemas/application.js";

const read = (p) => fs.readFileSync(p, "utf8");
const minimalApp = {
  id: 1,
  name: "Site",
  domain: "example.com",
  site_type: "wordpress",
  status: "active",
};
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const site = { id: 1, path: "/home/qa/shop/public_html", php_version: "8.3", php_binary: "/usr/bin/php8.3" };

// f5a48ef6 (Dipali, 28 Oct): templates say `{php}`, each PHP site carries `php_binary`.
test("a picked site's template runs that site's PHP binary", () => {
  assert.equal(resolveTemplate("{php} {path}/wp-cron.php", { dir: site.path, site }), "/usr/bin/php8.3 /home/qa/shop/public_html/wp-cron.php");
  assert.equal(resolveTemplate("{php} {path}/artisan schedule:run", { dir: "/x/", site: { php_binary: "/usr/local/lsws/lsphp83/bin/php" } }), "/usr/local/lsws/lsphp83/bin/php /x/artisan schedule:run");
});

test("a typed path, or a site without PHP, runs the server default php; the path waits until given", () => {
  assert.equal(resolveTemplate("{php} {path}/wp-cron.php", { dir: "/srv/blog" }), "php /srv/blog/wp-cron.php");
  assert.equal(resolveTemplate("{php} {path}/wp-cron.php", { dir: "/srv/blog", site: { php_binary: null } }), "php /srv/blog/wp-cron.php");
  assert.equal(resolveTemplate("{php} {path}/wp-cron.php"), "php {path}/wp-cron.php");
});

test("today's templates (plain php) come out exactly as before", () => {
  assert.equal(resolveTemplate("php {path}/wp-cron.php", { dir: site.path, site }), "php /home/qa/shop/public_html/wp-cron.php");
});

test("php_binary survives the application schema, and a hand-typed {php} is refused", () => {
  const parsed = applicationSchema.parse({ ...minimalApp, php_binary: "/usr/bin/php8.3" });
  assert.equal(parsed.php_binary, "/usr/bin/php8.3");
  assert.match(read("lib/schemas/cronjob.js"), /\.refine\(\(v\) => !v\.includes\(PHP_TOKEN\), "unresolvedPhp"\)/);
});

test("the field uses the helper for templates and the quick-start, and names the PHP when a site is picked", () => {
  const field = read("components/cron-jobs/command-field.jsx");
  assert.match(field, /const resolved = resolveTemplate\(tpl, \{ dir, site, placeholder, phpPlaceholder \}\);/);
  assert.match(field, /onPath\(site\.path, site\);/);
  assert.match(field, /resolveTemplate\(starter\.command, \{ placeholder, phpPlaceholder \}\)/);
  assert.match(field, /selectedSite\?\.php_binary && template\?\.includes\(phpPlaceholder\)/);
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    assert.ok(m.validation.unresolvedPhp && !/[{}]/.test(m.validation.unresolvedPhp), `${locale}: unresolvedPhp`);
    assert.ok(m.cronJobs.form.phpFromSite.includes("{version}") && m.cronJobs.form.phpFromSite.includes("{binary}"), `${locale}: phpFromSite`);
  }
});
