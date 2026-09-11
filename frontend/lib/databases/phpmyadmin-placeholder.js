/**
 * The document the new tab shows while the one-click login is being prepared.
 *
 * The blank frame cannot be removed. The tab has to be opened synchronously
 * off the click or the browser blocks it as unsolicited, and the URL it will
 * go to does not exist until a request comes back — so there is always a gap.
 * Unexplained, that gap reads as a tab that opened by mistake, which is how it
 * was reported on every stack.
 *
 * Pure and here rather than in the component, for the same reason
 * `phpmyadmin-state.js` is: the escaping is the part that can be wrong, and it
 * is worth testing without a browser.
 */

const ENTITIES = {
  "&": "&amp;",
  "<": "&lt;",
  ">": "&gt;",
  '"': "&quot;",
  "'": "&#39;",
};

/**
 * Escaped because the message is translated text — a string from a file, not a
 * literal in the caller. `&` first is implicit in the single pass: replacing
 * character by character cannot double-encode an entity it just produced.
 */
export function escapeHtml(value) {
  return String(value ?? "").replace(
    /[&<>"']/g,
    (character) => ENTITIES[character],
  );
}

/**
 * No stylesheet, no image, no script: this document is replaced within a
 * second, and anything it fetched would still be in flight when it went away.
 * The dark-scheme rule is inline for the same reason — the tab usually opens
 * over a dark panel, and a full-white flash is the brightest thing on screen.
 */
export function placeholderDocument(message) {
  return (
    '<!DOCTYPE html><html><head><meta charset="utf-8"><title>phpMyAdmin</title>' +
    "<style>body{margin:0;display:flex;align-items:center;justify-content:center;" +
    "height:100vh;font:400 15px/1.5 system-ui,-apple-system,'Segoe UI',sans-serif;" +
    "color:#444;background:#fff}" +
    "@media (prefers-color-scheme:dark){body{background:#0a0a0a;color:#a1a1aa}}</style>" +
    `</head><body><p>${escapeHtml(message)}</p></body></html>`
  );
}
