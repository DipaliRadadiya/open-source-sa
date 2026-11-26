export function hiddenToggleHref({ appId, path = "", showHidden = true }) {
  const params = new URLSearchParams();

  if (path) params.set("path", path);

  params.set("hidden", showHidden ? "0" : "1");

  const query = params.toString();

  return `/applications/${appId}/files${query ? `?${query}` : ""}`;
}
