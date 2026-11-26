// Browsers block popups opened more than ~5 s after the click.

export function openUrlInNewTab(url) {
  const tab = window.open(url, "_blank");
  if (!tab) return false;
  try {
    tab.opener = null;
  } catch {
    // already cross-origin
  }
  return true;
}

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
