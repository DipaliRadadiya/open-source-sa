import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * A filter dropdown with the "everything" option built in; the presentation
 * half of `FacetSelect`, for screens that filter in memory.
 *
 * `value` is "all" when nothing is selected (same sentinel as `FacetSelect`).
 */
// `label` names the control for screen readers; the trigger shows only the choice.
export function FilterSelect({ value, onChange, allLabel, options, className, label }) {
  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger className={className} aria-label={label}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">{allLabel}</SelectItem>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
