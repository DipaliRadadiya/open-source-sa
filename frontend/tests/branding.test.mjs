import { test } from "node:test";
import assert from "node:assert/strict";
import { brandingSchema } from "../lib/schemas/branding.js";
import {
  DEFAULT_BRANDING,
  mergeBranding,
  usableAsset,
} from "../lib/branding/merge-branding.js";

// The rule: branding wins wherever it has a value, defaults only fill gaps.
// The old fetcher fell back all-or-nothing, so one unusable field discarded a
// perfectly good name, logo and brand colour along with it.

test("a single unusable field does not discard the rest of the branding", () => {
  const merged = mergeBranding({
    name: "Acme Cloud",
    logo: "https://acme.test/logo.png",
    favicon: null,
    primary_color: "#ff0000",
  });

  assert.equal(merged.name, "Acme Cloud");
  assert.equal(merged.logo, "https://acme.test/logo.png");
  assert.equal(merged.primary_color, "#ff0000");
  // Only the gap falls back.
  assert.equal(merged.favicon, DEFAULT_BRANDING.favicon);
});

test("a self-hosted panel may serve its own assets from a relative path", () => {
  // z.string().url() rejected these, which took the whole object down with it.
  const merged = mergeBranding({ favicon: "/storage/brand/favicon.png" });
  assert.equal(merged.favicon, "/storage/brand/favicon.png");
});

test("branding wins even when the value is unexpected but usable", () => {
  const merged = mergeBranding({
    favicon: "  https://acme.test/icon.svg  ",
    primary_color: "rebeccapurple",
  });
  assert.equal(merged.favicon, "https://acme.test/icon.svg");
  assert.equal(merged.primary_color, "rebeccapurple");
});

test("blank and absent values fall back rather than blanking the tab icon", () => {
  for (const favicon of ["", "   ", null, undefined, 42, {}, []]) {
    assert.equal(mergeBranding({ favicon }).favicon, DEFAULT_BRANDING.favicon);
  }
  assert.deepEqual(mergeBranding(null), DEFAULT_BRANDING);
  assert.deepEqual(mergeBranding("nonsense"), DEFAULT_BRANDING);
});

test("an asset that could not be fetched or rendered is refused", () => {
  // These would land in <link href> / <img src>. A bare word is a config typo,
  // not an asset; the rest are schemes we will not emit.
  for (const value of [
    "logo.png",
    "javascript:alert(1)",
    "data:image/png;base64,AAA",
    "//evil.test/logo.png",
    "ftp://acme.test/logo.png",
  ]) {
    assert.equal(usableAsset(value), false, value);
  }

  for (const value of ["https://acme.test/a.png", "http://acme.test/a.png", "/a.png"]) {
    assert.equal(usableAsset(value), true, value);
  }
});

test("an unreadable colour keeps the default brand colour, not no colour", () => {
  // generatePalette returns null for a colour it cannot parse, which drops the
  // theme override entirely — worse than falling back to the default blue.
  assert.equal(mergeBranding({ primary_color: "not-a-colour" }).primary_color,
    DEFAULT_BRANDING.primary_color);
});

test("the schema degrades one bad field instead of failing the response", () => {
  const parsed = brandingSchema.safeParse({
    name: "Acme Cloud",
    favicon: 42,
    primary_color: "#ff0000",
  });

  assert.equal(parsed.success, true);
  assert.equal(parsed.data.favicon, null);
  assert.equal(mergeBranding(parsed.data).name, "Acme Cloud");
});

test("the live payload shape still resolves to real branding", () => {
  const merged = mergeBranding({
    name: "ServerAvatar",
    logo: "https://app.serveravatar.com/logo/SaLogoDark.png",
    logo_dark: "https://app.serveravatar.com/logo/dark-logo.png",
    icon: "https://app.serveravatar.com/logo/logo-sm.png",
    icon_dark: "https://app.serveravatar.com/logo/dark-logo-sm.png",
    favicon: "https://app.serveravatar.com/logo/logo-sm.png",
    primary_color: "#076aff",
    central_name: "ServerAvatar Central",
  });

  assert.deepEqual(merged, DEFAULT_BRANDING);
});
