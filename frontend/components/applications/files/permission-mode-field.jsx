import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import {
  AUDIENCES,
  hasPermission,
  isWorldWritable,
  withPermission,
} from "@/lib/files/describe-mode";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { useModeSentence } from "@/components/applications/files/use-mode-sentence";

// Presets first, with an escape hatch for the rest, like Cron Jobs' schedule
// field, so no one needs to know octal.
const PRESETS = [
  { mode: "644", labelKey: "permissionsDialog.preset644" },
  { mode: "755", labelKey: "permissionsDialog.preset755" },
  { mode: "600", labelKey: "permissionsDialog.preset600" },
];

// The octal mode is what gets sent, just not what gets typed.
export function PermissionModeField({ mode, onChange, invalid = false }) {
  const t = useTranslations("applications.files");
  const sentenceFor = useModeSentence();
  // Only for the checkbox labels; the sentence comes from the shared hook.
  const permWords = {
    read: t("permissionsDialog.verbRead"),
    write: t("permissionsDialog.verbWrite"),
    execute: t("permissionsDialog.verbExecute"),
  };

  // Live plain-language readout for presets and custom values alike. Shared with
  // the whole-site reset dialog so the two sentences cannot drift.
  const descriptionText = sentenceFor(mode);

  return (
    <div className="space-y-3">
      {/* Labels wrap on a phone (widest in Spanish); h-auto overrides the preset height. */}
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
        {PRESETS.map((p) => (
          <Button
            key={p.mode}
            type="button"
            size="sm"
            variant={mode === p.mode ? "default" : "outline"}
            onClick={() => onChange(p.mode)}
            className="h-auto min-h-8 w-full whitespace-normal py-1.5 text-left sm:w-auto"
          >
            {t(p.labelKey)}
          </Button>
        ))}
      </div>

      {/* Columns narrow on a phone so "Everyone else" fits; the gap separates wide-locale headers. */}
      <div className={invalid ? "overflow-hidden rounded-lg border border-destructive" : "overflow-hidden rounded-lg border"}>
        <div className="grid grid-cols-[1fr_repeat(3,2.75rem)] items-center gap-x-1.5 gap-y-1 px-3 py-2 text-xs font-medium text-muted-foreground sm:grid-cols-[1fr_repeat(3,4.5rem)]">
          <span />
          <span className="text-center">{t("permissionsDialog.read")}</span>
          <span className="text-center">{t("permissionsDialog.write")}</span>
          <span className="text-center">{t("permissionsDialog.execute")}</span>
        </div>
        <div className="divide-y border-t">
          {AUDIENCES.map((audience) => (
            <div
              key={audience}
              className="grid grid-cols-[1fr_repeat(3,2.75rem)] items-center gap-x-1.5 px-3 py-2 sm:grid-cols-[1fr_repeat(3,4.5rem)]"
            >
              <span className="text-sm font-medium">
                {t(`permissionsDialog.audience.${audience}`)}
              </span>
              {["read", "write", "execute"].map((permission) => (
                <span key={permission} className="flex justify-center">
                  <Checkbox
                    checked={hasPermission(mode, audience, permission)}
                    aria-label={t("permissionsDialog.boxLabel", {
                      audience: t(`permissionsDialog.audience.${audience}`),
                      permission: permWords[permission],
                    })}
                    onCheckedChange={(next) =>
                      onChange(withPermission(mode, audience, permission, next === true))
                    }
                  />
                </span>
              ))}
            </div>
          ))}
        </div>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-2">
        {descriptionText ? (
          <p className="text-xs leading-relaxed text-muted-foreground">{descriptionText}</p>
        ) : (
          <span />
        )}
        {/* Kept small: the number still means something to some users and is what the
            server receives. */}
        <span className="font-mono text-xs tabular-nums text-muted-foreground">{mode}</span>
      </div>

      {/* World-writable: almost never intended, so it is the one combination flagged. */}
      {isWorldWritable(mode) ? (
        <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 px-3 py-2 text-xs text-warning">
          <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
          {t("columns.worldWritableHint")}
        </p>
      ) : null}
    </div>
  );
}
