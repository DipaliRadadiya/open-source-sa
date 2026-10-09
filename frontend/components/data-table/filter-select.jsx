import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { SELECT_WELL } from "@/components/data-table/toolbar-well";
import { cn } from "@/lib/utils";

// Presentation half of `FacetSelect` for in-memory filters; `value` is "all" when unset.
// `label` names the control for screen readers; the trigger shows only the choice.
export function FilterSelect({ value, onChange, allLabel, options, className, label }) {
  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger className={cn(SELECT_WELL, className)} aria-label={label}>
        {/* Named here, not left to Radix: it fills the trigger only after hydration,
            so on a slow connection the filters showed blank (Krishna, 6 Oct). */}
        <SelectValue>
          {value === "all" ? allLabel : (options.find((option) => option.value === value)?.label ?? allLabel)}
        </SelectValue>
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
