import { useTranslations } from "next-intl";

// The installed version after the unit name ("nginx · 1.24.0"); dpkg's own string on hover.
// Beside the friendly name it broke "PostgreSQL 16.15" across lines in the table's narrow column.
// Null when there is nothing to read (not from a package, or still installing): show nothing.
export function ServiceVersion({ version, packageVersion }) {
  const t = useTranslations("services");
  if (!version) return null;
  // The space sits outside the unbreakable part, so a narrow column wraps before "· 1.24.0"
  // instead of cutting it off.
  return (
    <>
      {" "}
      <span
        className="whitespace-nowrap tabular-nums"
        title={packageVersion ? t("packageVersion", { version: packageVersion }) : undefined}
      >
        · {version}
      </span>
    </>
  );
}
