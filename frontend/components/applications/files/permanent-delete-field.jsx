import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";

/**
 * The one control that turns a recoverable delete into a real one.
 *
 * A checkbox, default off, inside the confirm dialog — cPanel's shape, and the
 * reason it wins is that the safe path is the one you get by not reading. (The
 * one panel that inverts it, DirectAdmin, defaults to trash but then offers no
 * way to see or restore anything, so the switch is the whole feature.)
 *
 * It has to stay reachable rather than being hidden behind an admin setting:
 * the trash is how you *lose* disk space, and someone deleting 40 GB to free a
 * full disk, then seeing nothing freed, is right to call that broken.
 */
export function PermanentDeleteField({ id = "delete-permanent", checked, onChange, disabled }) {
  const t = useTranslations("applications.files.delete");

  return (
    /* The tint follows the checkbox rather than sitting on permanently.
       Unticked is the safe, default path — dressing it in destructive red says
       "danger" about the state where nothing is lost, and then has nowhere left
       to go when the reader ticks the box and something is. Red here means the
       delete is now irreversible, and it appears at the moment that becomes
       true. */
    <div
      className={cn(
        "flex items-start gap-2.5 rounded-lg border p-3 transition-colors",
        checked ? "border-destructive/40 bg-destructive/10" : "bg-muted/40",
      )}
    >
      <Checkbox
        id={id}
        checked={checked}
        onCheckedChange={(next) => onChange(next === true)}
        disabled={disabled}
        className="mt-0.5"
      />
      <div className="space-y-1">
        <Label htmlFor={id} className="text-sm font-medium">
          {t("permanent.label")}
        </Label>
        <p
          className={cn(
            "text-xs leading-relaxed",
            checked ? "text-destructive" : "text-muted-foreground",
          )}
        >
          {t("permanent.hint")}
        </p>
      </div>
    </div>
  );
}
