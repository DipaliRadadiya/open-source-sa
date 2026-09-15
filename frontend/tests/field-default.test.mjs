import test from "node:test";
import assert from "node:assert/strict";
import { declaredDefault, toggleValue } from "../lib/applications/field-default.js";

test("a toggle's default survives every shape PHP encodes it as", () => {
  /*
   * The one that bit: `Boolean("false")` is `true`, so the switch drew ON
   * while the field held a string the API answers with "must be true or
   * false".
   */
  assert.equal(toggleValue(false), false);
  assert.equal(toggleValue("false"), false);
  assert.equal(toggleValue("0"), false);
  assert.equal(toggleValue(0), false);
  assert.equal(toggleValue(""), false);
  assert.equal(toggleValue(true), true);
  assert.equal(toggleValue("true"), true);
  assert.equal(toggleValue(1), true);
});

test("no declared default is undefined, not an empty string", () => {
  /*
   * The distinction the select bug turned on. A Controller given `""` hands
   * that to Radix, which latches it as its controlled value and fires
   * `onValueChange("")` straight back — wiping whatever the form had just
   * been given. `undefined` leaves react-hook-form's own behaviour alone.
   */
  assert.equal(declaredDefault({ type: "text" }), undefined);
  assert.equal(declaredDefault({ type: "text", default: null }), undefined);
  assert.equal(declaredDefault({ type: "text", default: "" }), undefined);
});

test("false and zero are answers, not absences", () => {
  assert.equal(declaredDefault({ type: "toggle", default: false }), false);
  assert.equal(declaredDefault({ type: "toggle", default: "false" }), false);
  assert.equal(declaredDefault({ type: "number", default: 0 }), 0);
});

test("the type declared is the type returned", () => {
  assert.equal(declaredDefault({ type: "number", default: "8" }), 8);
  assert.equal(declaredDefault({ type: "select", default: "mariadb" }), "mariadb");
  assert.equal(declaredDefault({ type: "text", default: "/web" }), "/web");
  assert.equal(declaredDefault({ type: "toggle", default: 1 }), true);
});
