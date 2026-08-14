"use client";

import { useTranslations } from "next-intl";
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
    <div className="flex items-start gap-2.5 rounded-lg border border-destructive/30 bg-destructive/5 p-3">
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
        <p className="text-xs leading-relaxed text-muted-foreground">{t("permanent.hint")}</p>
      </div>
    </div>
  );
}
