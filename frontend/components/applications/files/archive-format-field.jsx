import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChoiceField } from "@/components/ui/choice-field";
import {
  ARCHIVE_FORMATS,
  archiveFormatOf,
  withArchiveFormat,
} from "@/lib/files/path-helpers";

// zip drops Unix permissions; tar keeps mode, owner and symlinks.
export function useArchiveFormat() {
  const t = useTranslations("applications.files.archiveFormat");
  // Kept apart from the path, so a name typed without an extension still gets the
  // chosen format.
  const [chosen, setChosen] = useState(ARCHIVE_FORMATS[0]);
  return {
    chosen,
    setChosen,
    options: ARCHIVE_FORMATS.map((value) => ({
      value,
      label: t(`options.${value === ".zip" ? "zip" : "targz"}.label`),
      hint: t(`options.${value === ".zip" ? "zip" : "targz"}.hint`),
    })),
    legend: t("legend"),
    // The extension is the source of truth, so a hand-typed name still highlights the
    // right button.
    validate: (value) => (archiveFormatOf(value) ? null : t("mustBeArchive")),
    // A bare name gets the chosen extension; a folder path ("keep/") is left for
    // validate to refuse rather than turned into a hidden ".zip".
    complete: (value) => (!value || value.endsWith("/") || archiveFormatOf(value) ? value : `${value}${chosen}`),
  };
}

export function ArchiveFormatField({ options, legend, chosen, setChosen, value, setValue, busy, suggest }) {
  const typed = archiveFormatOf(value);
  return (
    <fieldset className="space-y-2" disabled={busy}>
      <legend className="pb-2 text-sm font-medium">{legend}</legend>
      <ChoiceField
        value={typed === ".tar.gz" || typed === ".tgz" ? ".tar.gz" : typed === ".zip" ? ".zip" : chosen}
        onChange={(next) => {
          setChosen(next);
          // Re-suggest an untouched suggestion for the new format: the "-2" in "src-2.zip"
          // only meant src.zip was taken.
          const untouched = suggest && ARCHIVE_FORMATS.some((ext) => value === suggest(ext));
          setValue(untouched ? suggest(next) : withArchiveFormat(value, next));
        }}
        options={options}
        disabled={busy}
        name="archive-format"
      />
    </fieldset>
  );
}
