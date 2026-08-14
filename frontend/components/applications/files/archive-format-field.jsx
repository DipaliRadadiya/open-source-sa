"use client";

import { useTranslations } from "next-intl";
import { ChoiceField } from "@/components/ui/choice-field";
import {
  ARCHIVE_FORMATS,
  archiveFormatOf,
  withArchiveFormat,
} from "@/lib/files/path-helpers";

/**
 * Which container the archive goes into.
 *
 * It exists because the two are not interchangeable: **zip does not carry Unix
 * permissions**, so a folder zipped and unzipped comes back with whatever modes
 * the extractor picked — a 0600 `wp-config.php` does not stay 0600. tar keeps
 * mode, owner and symlinks, which is what "make me a copy before I touch this"
 * actually means. The API has written both for a while; only this UI insisted
 * everything was a zip.
 *
 * The choice writes straight into the path field rather than sitting beside it,
 * so the filename shown is always the filename created.
 */
export function useArchiveFormat() {
  const t = useTranslations("applications.files.archiveFormat");
  return {
    options: ARCHIVE_FORMATS.map((value) => ({
      value,
      label: t(`options.${value === ".zip" ? "zip" : "targz"}.label`),
      hint: t(`options.${value === ".zip" ? "zip" : "targz"}.hint`),
    })),
    legend: t("legend"),
    // The extension is the truth: someone who types their own name straight
    // into the path field still gets the right button highlighted.
    validate: (value) => (archiveFormatOf(value) ? null : t("mustBeArchive")),
  };
}

export function ArchiveFormatField({ options, legend, value, setValue, busy }) {
  return (
    <fieldset className="space-y-2" disabled={busy}>
      <legend className="pb-2 text-sm font-medium">{legend}</legend>
      <ChoiceField
        value={archiveFormatOf(value) === ".tar.gz" || archiveFormatOf(value) === ".tgz" ? ".tar.gz" : ".zip"}
        onChange={(next) => setValue(withArchiveFormat(value, next))}
        options={options}
        disabled={busy}
        name="archive-format"
      />
    </fieldset>
  );
}
