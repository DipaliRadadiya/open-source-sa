import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), "utf8");
}

const jumpLink = read("components/ui/section-jump-link.jsx");
const attentionStrip = read("components/applications/attention-strip.jsx");
const protectionCard = read("components/applications/protection-card.jsx");

test("the Security action keeps a native fragment-link fallback", () => {
  assert.match(attentionStrip, /item\.href\.startsWith\("#"\)/);
  assert.match(attentionStrip, /<SectionJumpLink[^>]*href=\{item\.href\}/);
  assert.match(jumpLink, /<Link href=\{href\} prefetch=\{false\} onClick=\{jump\}>/);
});

test("same-page jumps scroll, focus, and respect reduced motion", () => {
  assert.match(jumpLink, /document\.getElementById\(id\)/);
  assert.match(jumpLink, /prefers-reduced-motion: reduce/);
  assert.match(jumpLink, /behavior: reduceMotion \? "auto" : "smooth"/);
  assert.match(jumpLink, /target\.scrollIntoView\(/);
  assert.match(jumpLink, /target\.focus\(\{ preventScroll: true \}\)/);
});

test("the destination cue restarts and removes itself", () => {
  assert.match(jumpLink, /const HIGHLIGHT_MS = 1200/);
  assert.match(jumpLink, /target\.removeAttribute\("data-jump-highlight"\)/);
  assert.match(jumpLink, /requestAnimationFrame\(\(\) =>/);
  assert.match(jumpLink, /target\.setAttribute\("data-jump-highlight", "true"\)/);
  assert.match(jumpLink, /clearTimeout\(highlightTimer\.current\)/);
  assert.match(jumpLink, /cancelAnimationFrame\(highlightFrame\.current\)/);
});

test("the Protection card exposes a subtle accessible focus target", () => {
  assert.match(protectionCard, /id="security"/);
  assert.match(protectionCard, /tabIndex=\{-1\}/);
  assert.match(protectionCard, /aria-labelledby="security-heading"/);
  assert.match(protectionCard, /data-\[jump-highlight=true\]:after:opacity-100/);
  assert.match(protectionCard, /after:border-primary\/40/);
  assert.match(protectionCard, /after:duration-200/);
  assert.match(protectionCard, /motion-reduce:after:transition-none/);
  assert.doesNotMatch(protectionCard, /animate-(?:pulse|ping|bounce)/);
});
