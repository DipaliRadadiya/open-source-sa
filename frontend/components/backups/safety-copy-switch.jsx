import { Switch } from "@/components/ui/switch";

// "Create a copy first" (restore, staging push/pull). The hint only promises an undo while the switch
// is on; the caller shows its own red warning when it is off.
export function SafetyCopySwitch({ id, checked, onCheckedChange, disabled, label, hint }) {
  return (
    <label htmlFor={id} className="flex cursor-pointer items-start justify-between gap-4 rounded-lg border p-3">
      <span className="space-y-1">
        <span className="block text-sm font-medium">{label}</span>
        {checked ? <span className="block text-xs text-muted-foreground">{hint}</span> : null}
      </span>
      <Switch id={id} checked={checked} onCheckedChange={onCheckedChange} disabled={disabled} />
    </label>
  );
}
