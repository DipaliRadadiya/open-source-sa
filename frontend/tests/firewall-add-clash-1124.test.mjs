import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

// 24 Nov (Krishna): a service chip offered a rule that already existed, and the refusal sat
// at the top of a dialog scrolled down to its button, so nothing seemed to happen.
test("a rule the server would refuse is said before sending, on the chip and the button", () => {
  const dlg = read("components/firewall/add-rule-dialog.jsx");
  // Same match as RefusesDuplicateRules: port, range, protocol and source, any action.
  assert.match(dlg, /\(r\.source_ip \?\? ""\) === \(values\.source_ip\?\.trim\(\) \?\? ""\)/);
  assert.doesNotMatch(dlg.slice(dlg.indexOf("const clashWith"), dlg.indexOf("const clashReason")), /r\.action/);
  assert.match(dlg, /\(clash\.action \?\? "allow"\) === values\.action \? t\("add\.duplicate"\) : t\("add\.conflict"\)/);
  assert.match(dlg, /disabled=\{isSubmitting \|\| Boolean\(blockReason\)\} disabledReason=\{blockReason\}/);
  // The chip is judged by the protocol it would fill: the service's own until the user
  // picks one, theirs after (Krishna, 24 Nov: UDP chosen, HTTPS still greyed).
  assert.match(dlg, /const taken = clashReason\(clashWith\(Number\(p\.port\), null, presetProtocol\(p\)\)\);/);
  assert.match(dlg, /const presetProtocol = \(preset\) => \(protocolChosen \? values\.protocol : \(preset\.protocol \?\? "tcp"\)\);/);
  assert.match(dlg, /if \(!protocolChosen && next\.protocol\) form\.setValue\("protocol", next\.protocol\);/);
  assert.match(dlg, /form\.setValue\("protocol", next, \{ shouldDirty: true,/);
  assert.match(dlg, /disabled=\{Boolean\(taken\)\}/);
});

test("the server's refusal scrolls into view", () => {
  const dlg = read("components/firewall/add-rule-dialog.jsx");
  assert.match(dlg, /if \(serverError\) serverErrorRef\.current\?\.scrollIntoView/);
  assert.match(dlg, /ref=\{serverErrorRef\}/);
});

test("an empty errors object no longer swallows the 422's message", () => {
  assert.match(read("lib/api/handle-validation-error.js"), /if \(errors && Object\.keys\(errors\)\.length > 0 && form\)/);
});

test("the conflict sentence exists in every locale", () => {
  for (const l of LOCALES) assert.ok(JSON.parse(read(`messages/${l}.json`)).firewall.add.conflict, l);
});
