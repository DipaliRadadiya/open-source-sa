import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChoiceField } from "@/components/ui/choice-field";
import {
  ARCHIVE_FORMATS,
  archiveFormatOf,
  withArchiveFormat,
} from "@/lib/files/path-helpers";

export function useArchiveFormat() {
  const t = useTranslations("applications.files.archiveFormat");
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
    validate: (value) => (archiveFormatOf(value) ? null : t("mustBeArchive")),
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
