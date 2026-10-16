import { test, describe } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import {
  CATCH_ALLS,
  SEARCH_ENGINES,
  SHAPE,
} from "../lib/schemas/bot-rule.js";
import { BACKUP_DEFAULT_TIME } from "../lib/schemas/backup.js";
import { isValidApplicationDomain } from "../lib/schemas/application.js";
import { PHP_SIZE_PATTERN, phpSizeToBytes } from "../lib/schemas/php-settings.js";

/**
 * Holds this frontend's copies of backend rules up against the backend.
 *
 * Several things here are deliberate duplicates — a validation regex, two word
 * lists, a default hour — because the field has to answer before the request
 * goes out. Every one of them is a copy that goes stale silently: the PHP
 * changes, nothing here fails, and the first person to find out is a user
 * being refused something the server would have accepted (or accepted
 * something it then refuses).
 *
 * These read the PHP source rather than call the API, so they work with the
 * backend offline — which is when this was written.
 *
 * Skipped, not failed, when the backend checkout is not on this machine: a
 * frontend clone on its own is a legitimate way to work, and a test that
 * cannot run is not a test that failed. It IS a real failure if the file is
 * there and the values disagree.
 */
const BACKEND = "/var/www/sv-oss/backend";

const read = (relative) => {
  const file = path.join(BACKEND, relative);
  return fs.existsSync(file) ? fs.readFileSync(file, "utf8") : null;
};

/** `'a', 'b', 'c'` out of a PHP array literal, as a Set of lowercase strings. */
const phpStringList = (source, constName) => {
  const block = source.match(new RegExp(`${constName}\\s*=\\s*\\[([\\s\\S]*?)\\];`));
  if (!block) return null;
  return new Set([...block[1].matchAll(/'([^']*)'/g)].map((m) => m[1].toLowerCase()));
};

/**
 * A PHP delimited pattern as a bare source string.
 *
 * Handles both the bare `'/…/'` of `preg_match` and Laravel's
 * `'regex:/…/'` rule string.
 */
const phpPattern = (source, after) => {
  // Trailing flags are part of the PHP literal (`/…/i`) but not of the
  // delimiters, so they are matched and dropped rather than breaking the read.
  const found = source.match(new RegExp(`${after}[\\s\\S]{0,200}?'(?:regex:)?/(.+?)/[a-z]*'`));
  // PHP escapes the delimiter; JavaScript regex literals here do not need to.
  return found ? found[1].replaceAll("\\/", "/") : null;
};

const botRules = read("app/Rules/BotUserAgent.php");
const hostnameRule = read("app/Rules/Hostname.php");
const domainRequests = ["CreateCloneRequest", "CreateStagingRequest", "StoreApplicationRequest"].map((name) => [
  name,
  read(`app/Http/Requests/Server/Application/${name}.php`),
]);
const backupTarget = read("app/Models/BackupTarget.php");

describe("frontend copies of backend rules", { skip: botRules === null ? "backend not on this machine" : false }, () => {
  test("BotUserAgent: the accepted shape is the same expression", () => {
    const php = phpPattern(botRules, "preg_match");
    assert.ok(php, "could not find the pattern in BotUserAgent.php");
    assert.equal(SHAPE.source.replaceAll("\\/", "/"), php);
  });

  test("BotUserAgent: the catch-all list has not moved", () => {
    assert.deepEqual(phpStringList(botRules, "CATCH_ALLS"), CATCH_ALLS);
  });

  test("BotUserAgent: the search-engine list has not moved", () => {
    assert.deepEqual(phpStringList(botRules, "SEARCH_ENGINES"), SEARCH_ENGINES);
  });

  test("Hostname: every domain field answers the way the server's rule does", () => {
    // Clone, staging and the create form all share one check; the server shares one rule.
    for (const [name, source] of domainRequests) {
      assert.match(source, /'domain' => \[[\s\S]{0,120}new Hostname/, `${name} no longer uses Hostname`);
    }

    // Hostname::validate, rebuilt from the patterns in the PHP file itself.
    const [label, tld] = [...hostnameRule.matchAll(/preg_match\('\/(.+?)\/'/g)].map((m) => new RegExp(m[1]));
    assert.ok(label && tld, "could not find the label and TLD patterns in Hostname.php");
    const server = (value) => {
      if (value.length > 253) return false;
      const labels = value.split(".");
      if (labels.length >= 2 && labels.every((l) => /^\d+$/.test(l))) return false;
      return labels.length >= 2 && labels.every((l) => label.test(l)) && tld.test(labels.at(-1));
    };

    for (const value of [
      "copy.blog.demo.test", "A-B.Example.COM", "x.co", "shop.xn--p1ai", "xn--d1acj3b.xn--p1ai",
      "localhost", "example.", "exa mple.com", "under_score.com", "a.b", "",
      "-shop.com", "shop-.com", "shop..com", "1.2.3.4", "shop.c0m", "shop.123", `${"a".repeat(64)}.com`,
    ]) {
      // Our forms lowercase before checking; the server's rule is case-blind.
      assert.equal(isValidApplicationDomain(value), server(value.toLowerCase()), value);
    }
  });

  test("SavePhpSettingsRequest: the size rule is the same expression", () => {
    const php = phpPattern(read("app/Http/Requests/Server/Application/SavePhpSettingsRequest.php"), "\\$size");
    assert.ok(php, "could not find the size regex in SavePhpSettingsRequest.php");
    assert.equal(PHP_SIZE_PATTERN.source, php);
  });

  test("ApplicationPhpSettings: unlimited is still budgeted as 128M", () => {
    // The frontend recomputes the budget live while someone types, so this
    // number existing in two places is deliberate — and therefore checked.
    const model = read("app/Models/ApplicationPhpSettings.php");
    assert.match(model, /return 128 \* 1024 \* 1024;/);
    assert.equal(phpSizeToBytes("-1"), 128 * 1024 * 1024);
  });

  test("ApplicationPhpSettings: the ceiling is still limit x children", () => {
    const model = read("app/Models/ApplicationPhpSettings.php");
    assert.match(
      model,
      /toBytes\(\(string\) \$effective\['memory_limit'\]\) \* \(int\) \$effective\['pm_max_children'\]/,
    );
  });

  test("BackupTarget: the daily cron still runs at the hour we show as the default", () => {
    const daily = backupTarget.match(/'daily'\s*=>\s*'(\d+)\s+(\d+)\s/);
    assert.ok(daily, "could not find the daily cron in BackupTarget.php");
    const [, minute, hour] = daily;
    assert.equal(
      BACKUP_DEFAULT_TIME,
      `${hour.padStart(2, "0")}:${minute.padStart(2, "0")}`,
    );
  });
});
