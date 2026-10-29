import { Switch } from "@/components/ui/switch";
import { cn } from "@/lib/utils";

// "Create a copy first" (restore, staging push/pull, .env and file saves). The hint only promises an
// undo while the switch is on; the caller shows its own red warning when it is off.
// `compact`: one line without a frame, for an editor whose height belongs to the text.
export function SafetyCopySwitch({ id, checked, onCheckedChange, disabled, label, hint, compact = false, className }) {
  if (compact) {
    return (
      <label htmlFor={id} className={cn("flex w-fit cursor-pointer items-center gap-2.5 text-sm", className)}>
        <Switch id={id} checked={checked} onCheckedChange={onCheckedChange} disabled={disabled} />
        <span>{label}</span>
      </label>
    );
  }
  return (
    <label htmlFor={id} className={cn("flex cursor-pointer items-start justify-between gap-4 rounded-lg border p-3", className)}>
      <span className="space-y-1">
        <span className="block text-sm font-medium">{label}</span>
        {checked && hint ? <span className="block text-xs text-muted-foreground">{hint}</span> : null}
      </span>
      <Switch id={id} checked={checked} onCheckedChange={onCheckedChange} disabled={disabled} />
    </label>
  );
}
