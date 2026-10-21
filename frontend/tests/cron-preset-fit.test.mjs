import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { availablePresets, presetFits } from "../lib/cron-jobs/preset-fit.js";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const presets = ["laravel", "wordpress", "moodle", "joomla", "nextcloud", "craftcms", "php_script"]
  .map((key) => ({ key, command: `php {path}/${key}` }))
  .concat({ key: "custom", command: null });
const keys = (list) => list.map((preset) => preset.key);

// Krishna, 20 Oct: a Docker server offered Laravel, Moodle… cron templates.
test("a Docker-only server offers only Custom", () => {
  const apps = [
    { site_type: "docker", serving_profile: "docker" },
    { site_type: "grafana", serving_profile: "docker" },
    { site_type: "wordpress_container", serving_profile: "docker" },
  ];
  assert.deepEqual(keys(availablePresets(presets, apps)), ["custom"]);
});

test("templates follow the applications present", () => {
  const apps = [
    { site_type: "wordpress", serving_profile: "php" },
    { site_type: "php", serving_profile: "php" },
    { site_type: "static", serving_profile: "static" },
    { site_type: "n8n", serving_profile: "node" },
  ];
  assert.deepEqual(keys(availablePresets(presets, apps)), ["laravel", "wordpress", "php_script", "custom"]);
  assert.equal(presetFits("laravel", { site_type: "wordpress", serving_profile: "php" }), false);
  assert.equal(presetFits("laravel", { site_type: "git", serving_profile: "php" }), true);
  assert.equal(presetFits("laravel", { site_type: "git", serving_profile: "node" }), false);
});

test("an unread application list keeps every template", () => {
  assert.equal(availablePresets(presets, [], false).length, presets.length);
});

test("the page filters the presets and the form filters the folders by template", () => {
  assert.match(read("app/(app)/cron-jobs/page.jsx"), /availablePresets\(commandPresets\.presets, sites\.applications, canManage && !sites\.failed\)/);
  const field = read("components/cron-jobs/command-field.jsx");
  assert.match(field, /application\.path && \(!templateKey \|\| presetFits\(templateKey, application\)\)/);
  assert.match(field, /if \(picked && !presetFits\(preset\.key, picked\)\)/);
});

test("with only Custom left, the template menu is not shown", () => {
  assert.match(read("components/cron-jobs/command-field.jsx"), /\{presets\.some\(\(p\) => p\.command\) \? \(/);
});

test("the command example is a PHP one only where a PHP template fits", () => {
  assert.match(read("components/cron-jobs/command-field.jsx"), /placeholder=\{presets\.some\(\(p\) => p\.command\) \? PHP_EXAMPLE : GENERIC_EXAMPLE\}/);
});
