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

const PRESETS = [
  { mode: "644", labelKey: "permissionsDialog.preset644" },
  { mode: "755", labelKey: "permissionsDialog.preset755" },
  { mode: "600", labelKey: "permissionsDialog.preset600" },
];

export function PermissionModeField({ mode, onChange, invalid = false }) {
  const t = useTranslations("applications.files");
  const sentenceFor = useModeSentence();
  const permWords = {
    read: t("permissionsDialog.verbRead"),
    write: t("permissionsDialog.verbWrite"),
    execute: t("permissionsDialog.verbExecute"),
  };

  const descriptionText = sentenceFor(mode);

  return (
    <div className="space-y-3">
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
        <span className="font-mono text-xs tabular-nums text-muted-foreground">{mode}</span>
      </div>

      {isWorldWritable(mode) ? (
        <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 px-3 py-2 text-xs text-warning">
          <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
          {t("columns.worldWritableHint")}
        </p>
      ) : null}
    </div>
  );
}
