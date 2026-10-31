// Dipali (30 Oct): moving an application to another network asks whether its own old
// network goes (default, as before) or stays — `remove_previous_network`.
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { moveOutcome, offersKeepChoice, ownNetworkName } from "../lib/docker/previous-network.js";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const own = { name: "sv-app-12_default" };
const qa = { name: "qa-net" };

test("the choice is offered only on a move to another network while the app's own network exists", () => {
  assert.equal(ownNetworkName(12), "sv-app-12_default");
  assert.equal(offersKeepChoice({ applicationId: 12, current: null, next: "qa-net", networks: [own, qa] }), true);
  assert.equal(offersKeepChoice({ applicationId: 12, current: "qa-net", next: "qa-net2", networks: [own, qa] }), true, "kept earlier, still removable");
  assert.equal(offersKeepChoice({ applicationId: 12, current: null, next: null, networks: [own] }), false, "no move");
  assert.equal(offersKeepChoice({ applicationId: 12, current: "qa-net", next: null, networks: [own, qa] }), false, "back to its own network");
  assert.equal(offersKeepChoice({ applicationId: 12, current: "qa-net", next: "qa-net", networks: [own, qa] }), false, "same network");
  assert.equal(offersKeepChoice({ applicationId: 12, current: null, next: "qa-net", networks: [qa] }), false, "its own network is already gone");
});

test("the message says what happened, read from the networks after the save", () => {
  assert.equal(moveOutcome({ applicationId: 12, removeRequested: true, networksAfter: [qa] }), "removed");
  assert.equal(moveOutcome({ applicationId: 12, removeRequested: false, networksAfter: [own, qa] }), "kept");
  assert.equal(moveOutcome({ applicationId: 12, removeRequested: true, networksAfter: [own, qa] }), "inUse", "asked to remove, still there");
});

test("both places that move an application send the choice only when it is offered", () => {
  const card = read("components/applications/container-card.jsx");
  assert.match(card, /\.\.\.\(keepChoice \? \{ remove_previous_network: removePrevious \} : \{\}\)/);
  assert.match(card, /useState\(true\)/, "ticked by default: today's behaviour");
  const dialog = read("components/docker/attach-site-dialog.jsx");
  assert.match(dialog, /\{ docker_network: name, \.\.\.\(keepChoice \? \{ remove_previous_network: removePrevious \} : \{\}\) \}/);
  assert.match(read("components/docker/docker-resources-panel.jsx"), /networks=\{networks\}/);
  for (const locale of LOCALES) {
    const t = JSON.parse(read(`messages/${locale}.json`)).docker.previousNetwork;
    for (const key of ["label", "hint", "removed", "kept", "inUse"]) assert.ok(t[key], `${locale}: docker.previousNetwork.${key}`);
    assert.match(t.label, /\{name\}/, locale);
    for (const key of ["removed", "kept", "inUse"]) assert.match(t[key], /\{network\}.*\{name\}|\{name\}.*\{network\}/s, `${locale}.${key}`);
  }
});
