/**
 * Opening a new tab for a one-click sign-in, and telling the reader what it is
 * waiting for.
 *
 * Shared, because there are two of these — phpMyAdmin and WordPress Magic
 * Login — and they are the same problem with the same constraint:
 *
 *   `window.open` is only permitted during a user gesture, and the gesture is
 *   gone the moment anything is awaited. So the tab must be opened FIRST and
 *   pointed somewhere once the token arrives. Opening it afterwards is a popup
 *   the browser did not see anybody ask for, which puts the "popup blocked"
 *   path in front of people who never blocked anything.
 *
 * The gap therefore cannot be removed. It can only be explained — and unexplained
 * it reads as a tab that opened by mistake, which is exactly how it gets
 * reported. phpMyAdmin has painted a holding page since 05429064; Magic Login
 * never did and sat white for ten seconds.
 */

const ENTITIES = {
  "&": "&amp;",
  "<": "&lt;",
  ">": "&gt;",
  '"': "&quot;",
  "'": "&#39;",
};

/**
 * Escaped because both arguments are translated text — strings from a file,
 * not literals in the caller. `&` first is implicit in the single pass:
 * replacing character by character cannot double-encode an entity it just
 * produced.
 */
export function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, (character) => ENTITIES[character]);
}

/**
 * No stylesheet, no image, no script: this document is replaced within a
 * second or two, and anything it fetched would still be in flight when it went
 * away. The dark-scheme rule is inline for the same reason — the tab usually
 * opens over a dark panel, and a full-white flash is the brightest thing on
 * screen.
 *
 * `title` is separate from `message` so the tab strip says what is coming
 * while the body says what is happening.
 */
export function placeholderDocument(message, title) {
  return (
    '<!DOCTYPE html><html><head><meta charset="utf-8">' +
    `<title>${escapeHtml(title)}</title>` +
    "<style>body{margin:0;display:flex;align-items:center;justify-content:center;" +
    "height:100vh;font:400 15px/1.5 system-ui,-apple-system,'Segoe UI',sans-serif;" +
    "color:#444;background:#fff}" +
    "@media (prefers-color-scheme:dark){body{background:#0a0a0a;color:#a1a1aa}}</style>" +
    `</head><body><p>${escapeHtml(message)}</p></body></html>`
  );
}

/**
 * A blank tab, opened while the click is still the click.
 *
 * MUST be called synchronously from the event handler — see the note at the
 * top of this file.
 *
 * Deliberately no `noopener` in the feature string: with it, `window.open`
 * returns null in every browser that honours it, and the handle is exactly
 * what this needs. `opener` is severed below instead, which buys the same
 * protection without losing the reference.
 */
export function openBlankTab() {
  const tab = window.open("", "_blank");
  if (!tab) return null;

  // The site is about to run its own code in that tab. Without this it could
  // reach back through window.opener and navigate the panel.
  try {
    tab.opener = null;
  } catch {
    // Cross-origin by the time we get here on some browsers; whatever the
    // caller does next still works, and this was defence in depth rather than
    // the only thing standing between the site and the panel.
  }

  return tab;
}

/**
 * `document.write` rather than DOM building: the handle is an `about:blank`
 * that has never navigated, and this is the one API that reliably replaces its
 * document before a `location.replace` follows. It inherits this page's
 * origin, so writing to it is permitted — severing `opener` does not change
 * that.
 *
 * Every call is guarded: this touches a document in a window the browser may
 * already have disowned, and a throw here would cost the click that is
 * fetching a token good for the next sixty seconds. A tab with no holding page
 * is worse-looking, not broken.
 */
export function paintPlaceholder(tab, message, title) {
  if (!tab) return;
  try {
    tab.document.write(placeholderDocument(message, title));
    tab.document.close();
  } catch {
    // Nothing to recover and nothing worth saying: the real page is still on
    // its way to this tab.
  }
}

/**
 * Give up on a tab we opened and will not be using.
 *
 * Never leave it: a blank tab that stays open after a picker appears looks
 * like the login half-worked.
 */
export function discardTab(tab) {
  try {
    tab?.close();
  } catch {
    // Already gone, or the browser refused. Nothing to recover.
  }
}
