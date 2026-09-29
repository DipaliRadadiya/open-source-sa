import test from "node:test";
import assert from "node:assert/strict";
import {
  timezoneOptions,
  timezoneOptionsWith,
  phpTimezoneOptionsWith,
} from "../lib/settings/timezone-options.js";

const GROUPS = [
  {
    region: "Asia",
    zones: [
      { value: "Asia/Kolkata", label: "Kolkata", offset: "+05:30" },
      { value: "Asia/Tokyo", label: "Tokyo", offset: null },
    ],
  },
  { region: "Etc", zones: [{ value: "Etc/UTC", label: "UTC", offset: "+00:00" }] },
];

test("flattens groups into value/label pairs", () => {
  assert.deepEqual(timezoneOptions(GROUPS), [
    { value: "Asia/Kolkata", label: "Kolkata (+05:30)" },
    { value: "Asia/Tokyo", label: "Tokyo" },
    { value: "Etc/UTC", label: "UTC (+00:00)" },
  ]);
});

test("every label is a string — a region object here crashes the page", () => {
  for (const option of timezoneOptions(GROUPS)) {
    assert.equal(typeof option.label, "string");
    assert.equal(typeof option.value, "string");
  }
});

test("survives a missing or malformed list", () => {
  assert.deepEqual(timezoneOptions([]), []);
  assert.deepEqual(timezoneOptions(undefined), []);
  assert.deepEqual(timezoneOptions([{ region: "Empty" }]), []);
});

test("a value the API does not list is kept selectable", () => {
  const options = timezoneOptionsWith(GROUPS, "Asia/Calcutta");
  assert.deepEqual(options[0], { value: "Asia/Calcutta", label: "Asia/Calcutta" });
  assert.equal(options.length, 4);
});

test("a known or empty value adds nothing", () => {
  assert.equal(timezoneOptionsWith(GROUPS, "Etc/UTC").length, 3);
  assert.equal(timezoneOptionsWith(GROUPS, "").length, 3);
});

test("PHP settings offer only zones the date.timezone save accepts", () => {
  const groups = [
    { region: "UTC", zones: [{ value: "UTC", label: "UTC", offset: "+00:00" }] },
    { region: "Etc", zones: [{ value: "Etc/UTC", label: "UTC", offset: "+00:00" }, { value: "Etc/GMT+5", label: "GMT+5", offset: "-05:00" }] },
    { region: "EST", zones: [{ value: "EST", label: "EST", offset: "-05:00" }] },
    { region: "Asia", zones: [{ value: "Asia/Kolkata", label: "Kolkata", offset: "+05:30" }, { value: "Asia/Istanbul", label: "Istanbul", offset: "+03:00" }] },
  ];
  const values = phpTimezoneOptionsWith(groups, "").map((o) => o.value);
  assert.deepEqual(values, ["UTC", "Asia/Kolkata"]);
  assert.equal(values.filter((v) => v.endsWith("UTC")).length, 1);
});
