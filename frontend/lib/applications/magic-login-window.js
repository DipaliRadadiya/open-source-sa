/**
 * Handing a magic-login token to a new tab, safely.
 *
 * Split out of the dialog because there are now two ways in — straight through
 * when a site has one administrator, and the picker when it has several — and
 * the care below is the kind that gets half-copied.
 */

/**
 * A blank tab, opened while the click is still the click.
 *
 * MUST be called synchronously from the event handler. `window.open` is only
 * permitted during a user gesture, and the gesture is gone the moment anything
 * is awaited — which is what the old flow did: it minted the token first and
 * opened afterwards, so the "popup blocked" path was reachable on an ordinary
 * click rather than only for people who had actually blocked popups. Opening
 * first and filling the tab in later removes that entirely.
 *
 * Deliberately no `noopener` in the feature string: with it, `window.open`
 * returns null in every browser that honours it, and the handle is exactly what
 * this needs. `opener` is severed below instead, which buys the same protection
 * without losing the reference.
 */
export function openBlankTab() {
  const tab = window.open("", "_blank");
  if (!tab) return null;

  // The site is about to run its own code in that tab. Without this it could
  // reach back through window.opener and navigate the panel.
  try {
    tab.opener = null;
  } catch {
    // Cross-origin by the time we get here on some browsers; the form below
    // still posts, and this was defence in depth rather than the only thing
    // standing between the site and the panel.
  }

  return tab;
}

/**
 * POST the token into the tab.
 *
 * A form submission, never a URL with the token in the query string. A token in
 * a URL is written to the site's access log, the browser's history and any
 * outbound Referer — and this one is worth a full administrator session for the
 * next sixty seconds.
 *
 * Built through the DOM, never by writing a string of HTML. The token and the
 * URL would both be interpolated into markup otherwise, and "the token is
 * alphanumeric so it cannot break out" stops being true the day the token
 * format changes.
 */
export function submitMagicLogin(tab, session) {
  const doc = tab.document;
  const form = doc.createElement("form");
  form.method = "POST";
  form.action = session.url;

  const field = doc.createElement("input");
  field.type = "hidden";
  field.name = "sv_magic_login";
  field.value = session.token;

  form.appendChild(field);
  (doc.body ?? doc.documentElement).appendChild(form);
  form.submit();
}

/**
 * Give up on a tab we opened and will not be using.
 *
 * Never leave it: a blank tab that stays open after the picker appears looks
 * like the login half-worked.
 */
export function discardTab(tab) {
  try {
    tab?.close();
  } catch {
    // Already gone, or the browser refused. Nothing to recover.
  }
}
