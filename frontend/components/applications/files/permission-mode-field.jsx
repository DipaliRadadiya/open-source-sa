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

// Presets first, an escape hatch for the rest — same pattern as Cron Jobs'
// schedule field. Most people setting permissions want one of these three;
// nobody should have to already know octal to use it.
const PRESETS = [
  { mode: "644", labelKey: "permissionsDialog.preset644" },
  { mode: "755", labelKey: "permissionsDialog.preset755" },
  { mode: "600", labelKey: "permissionsDialog.preset600" },
];

/**
 * Choosing a permission mode — one control, wherever it is asked for.
 *
 * There were three different answers to the same question on the Files screen:
 * this grid for one file, a row of raw octal buttons and a number box for a
 * multi-select, and a fixed 755/644 summary for the whole-site reset. The
 * middle one asked people to know octal for exactly the job the first one had
 * already decided they should not have to.
 *
 * The mode is still what gets sent — it is just no longer what gets typed.
 */
export function PermissionModeField({ mode, onChange, invalid = false }) {
  const t = useTranslations("applications.files");
  const sentenceFor = useModeSentence();
  // Only for the checkbox labels — the sentence itself comes from the shared hook.
  const permWords = {
    read: t("permissionsDialog.verbRead"),
    write: t("permissionsDialog.verbWrite"),
    execute: t("permissionsDialog.verbExecute"),
  };

  // A live, plain-language readout of whatever is selected — not just for a
  // custom value, but for the presets too, so "Read-only" does not have to be
  // taken on faith. Owner and "everyone else" collapse into one clause when
  // group and other match, which is true for all three presets.
  // Shared with the whole-site reset dialog, which describes its fixed 755 and
  // 644 the same way. Two copies of this sentence would drift the first time
  // either was reworded.
  const descriptionText = sentenceFor(mode);

  return (
    <div className="space-y-3">
      {/* The three answers almost everyone wants, still one click.
          Buttons are whitespace-nowrap by default, so on a phone the two longer
          labels ran straight out of the dialog's right edge — worse in Spanish,
          which is the widest locale. They wrap and take the full line instead:
          h-auto because the size preset fixes a height that one line fits. */}
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

      {/* Nine checkboxes rather than a number: "644" is only meaningful to
          someone who already knows the 4/2/1 mask, and everyone else was being
          asked to learn octal to change a file. The three columns are narrower
          on a phone — at 4.5rem each they claimed 13.5rem of a ~18rem dialog, leaving
          "Everyone else" one word per line. The gap is what separates the three
          header words at that width; without it "Escribir" and "Ejecutar" touch. */}
      <div className={invalid ? "overflow-hidden rounded-lg border border-destructive" : "overflow-hidden rounded-lg border"}>
        <div className="grid grid-cols-[1fr_repeat(3,2.75rem)] items-center gap-x-1.5 gap-y-1 px-3 py-2 text-[11px] font-medium text-muted-foreground sm:grid-cols-[1fr_repeat(3,4.5rem)]">
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
        {/* Kept, small: the number still means something to people who know it,
            and it is what the server is given. */}
        <span className="font-mono text-xs tabular-nums text-muted-foreground">{mode}</span>
      </div>

      {/* Anyone with a login on the box could change the file — almost never
          intended, and the one combination worth interrupting for. */}
      {isWorldWritable(mode) ? (
        <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 px-3 py-2 text-xs text-warning">
          <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
          {t("columns.worldWritableHint")}
        </p>
      ) : null}
    </div>
  );
}
