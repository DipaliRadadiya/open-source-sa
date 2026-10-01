import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";

// Not behind an admin setting: the trash keeps using disk space until emptied.
export function PermanentDeleteField({ id = "delete-permanent", checked, onChange, disabled }) {
  const t = useTranslations("applications.files.delete");

  return (
    /* The tint follows the checkbox: red appears only when the delete becomes
       irreversible. */
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
