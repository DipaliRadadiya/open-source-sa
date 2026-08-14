"use client";

import { useLocale, useTranslations } from "next-intl";
import { describeMode } from "@/lib/files/describe-mode";

/**
 * "Owner: read and write. Everyone else: read."
 *
 * Lifted out of the permission picker so the whole-site reset can say the same
 * thing about 755 and 644. That dialog used to show the two numbers and nothing
 * else, which is exactly the octal-literacy the picker exists to avoid — the
 * one screen that asks you to trust it with every file on the site was the one
 * screen that would not say what it was about to do.
 *
 * Returns null for a mode it cannot parse, so a caller renders nothing rather
 * than a half-sentence.
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

    // Group and others almost always agree; saying it twice reads as two rules
    // when there is one.
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
