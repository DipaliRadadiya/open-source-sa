import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * A filter dropdown, with the "everything" option built in.
 *
 * The presentation half of `FacetSelect`, split out so a screen that filters
 * in memory gets the identical control without the URL wiring. Two screens in
 * one feature were hand-assembling the same Select and only one of them
 * remembered the all-option's shape.
 *
 * `value` is "all" when nothing is selected — the same sentinel `FacetSelect`
 * uses, so the two cannot disagree about what "no filter" means.
 */
// `label` names the control for screen readers: the trigger only shows the
// current choice ("Any state"), which says nothing about what it filters.
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
