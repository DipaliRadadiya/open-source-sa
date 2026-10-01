/**
 * Opening sign-ins in a new tab only once the address is known, so the tab
 * opens straight onto the destination instead of `about:blank`. If the answer
 * arrives after the browser's ~5 s user-activation window, the browser shows
 * its own pop-up notice; allowing pop-ups once fixes later sign-ins.
 */

/** A GET address in a new tab. False when the browser refused. */
export function openUrlInNewTab(url) {
  const tab = window.open(url, "_blank");
  if (!tab) return false;
  // Stop the opened site reaching back through window.opener to navigate the panel.
  try {
    tab.opener = null;
  } catch {
    // Already cross-origin on some browsers; nothing left to cut.
  }
  return true;
}

/**
 * A POST in a new tab, for sign-ins that must not put their token in a URL.
 * Submitted from this page with `target="_blank"`, so the tab's first
 * navigation is the POST itself.
 */
export function postInNewTab(url, fields) {
  const form = document.createElement("form");
  form.method = "POST";
  form.action = url;
  form.target = "_blank";
  form.rel = "noopener";
  form.hidden = true;
  for (const [name, value] of Object.entries(fields)) {
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = name;
    input.value = value;
    form.appendChild(input);
  }
  document.body.appendChild(form);
  form.submit();
  form.remove();
  return true;
}
