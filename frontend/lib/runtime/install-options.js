export function installOptions(installable = [], installed = []) {
  const have = new Set(
    (Array.isArray(installed) ? installed : [])
      .filter((item) => (typeof item === "string" ? true : item?.status !== "failed"))
      .map((item) => (typeof item === "string" ? item : item?.version))
      .filter(Boolean)
      .map(String),
  );

  return (Array.isArray(installable) ? installable : [])
    .filter((option) => option?.version)
    .map((option) => ({ ...option, installed: have.has(String(option.version)) }));
}

export function firstInstallable(options = []) {
  return (Array.isArray(options) ? options : []).find((option) => !option.installed)?.version ?? "";
}

export function allInstalled(options = []) {
  const list = Array.isArray(options) ? options : [];
  return list.length > 0 && list.every((option) => option.installed);
}

export function resolveVersion(chosen, options = []) {
  const list = Array.isArray(options) ? options : [];
  const stillValid = list.some((option) => option.version === chosen && !option.installed);
  return stillValid ? chosen : firstInstallable(list);
}
