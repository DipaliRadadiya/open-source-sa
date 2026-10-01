/**
 * Where the "show/hide hidden files" control points. In the URL because the
 * listing is filtered on the server.
 */
export function hiddenToggleHref({ appId, path = "", showHidden = true }) {
  const params = new URLSearchParams();

  // Keep the current folder.
  if (path) params.set("path", path);

  // Always explicit, so the remembered cookie (lib/files/view-prefs) cannot override the click.
  params.set("hidden", showHidden ? "0" : "1");

  const query = params.toString();

  return `/applications/${appId}/files${query ? `?${query}` : ""}`;
}
