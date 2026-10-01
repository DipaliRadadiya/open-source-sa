import { useLocale, useTranslations } from "next-intl";
import { describeMode } from "@/lib/files/describe-mode";

/**
 * "Owner: read and write. Everyone else: read."
 * Shared by the permission picker and the whole-site reset. Returns null for a
 * mode it cannot parse, so callers render nothing rather than a half-sentence.
 */
export function useModeSentence() {
  const t = useTranslations("applications.files");
  const locale = useLocale();

  const words = {
    read: t("permissionsDialog.verbRead"),
    write: t("permissionsDialog.verbWrite"),
    execute: t("permissionsDialog.verbExecute"),
  };
  const listFormat = new Intl.ListFormat(locale, { style: "long", type: "conjunction" });
  const describe = (tokens) =>
    tokens.length
      ? listFormat.format(tokens.map((token) => words[token]))
      : t("permissionsDialog.noAccess");

  return (mode) => {
    const parts = describeMode(mode);
    if (!parts) return null;

    // Group and others usually agree; one clause instead of two identical ones.
    return parts.group.join() === parts.other.join()
      ? t("permissionsDialog.describeSimple", {
          owner: describe(parts.owner),
          rest: describe(parts.group),
        })
      : t("permissionsDialog.describeFull", {
          owner: describe(parts.owner),
          group: describe(parts.group),
          other: describe(parts.other),
        });
  };
}
