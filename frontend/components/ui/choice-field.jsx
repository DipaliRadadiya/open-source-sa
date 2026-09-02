import { useId } from "react";
import { cn } from "@/lib/utils";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";

/**
 * A setting whose options each have a consequence, written out.
 *
 * This replaces a switch and a dropdown on the security card. A switch called
 * "Allow password logins" makes the reader work out what "on" means, and a
 * dropdown option called "Key only" makes them translate jargon before they can
 * choose. Here the option IS the sentence, and its effect sits underneath it.
 *
 * `options`: [{ value, label, hint, tone, disabledReason }] — `tone: "warning"`
 * tints the option that weakens the server, so it reads as a cost rather than a
 * choice with no downside. `disabledReason` blocks one option and says why, in
 * place of its hint: the alternative is letting the user pick it, confirm a
 * frightening dialog, and only then be told the API will not allow it.
 */
/**
 * `variant="card"` gives each option a border and tints the chosen one.
 *
 * Opt-in, because the default deliberately has no box: five stacked options
 * with borders and fills read as a wall, which is why the plain variant exists.
 * Laid out as a few side-by-side choices, though, the box IS the affordance —
 * a radio dot alone leaves the selected option looking the same as the rest.
 */
export function ChoiceField({ value, onChange, options, disabled, name, className, variant }) {
  const id = useId();
  const card = variant === "card";

  return (
    <RadioGroup
      value={value}
      onValueChange={onChange}
      disabled={disabled}
      name={name}
      // `className` lets a caller lay the options out in columns where the
      // width is there for it — three stacked options is a lot of height to
      // spend inside a dialog. Default is unchanged: one per line.
      className={cn("gap-1.5", className)}
    >
      {options.map((option) => {
        const checked = option.value === value;
        const blocked = Boolean(option.disabledReason);
        const optionId = `${id}-${option.value}`;
        return (
          <label
            key={option.value}
            htmlFor={optionId}
            className={cn(
              // No box and no fill. The radio already says which one is chosen;
              // a border plus a tint plus a filled dot was three signals for one
              // fact, and five of those stacked read as a wall of boxes.
              "flex cursor-pointer items-start gap-3 rounded-md px-2 py-1.5 transition-colors",
              !checked && !blocked && !card && "hover:bg-muted/50",
              card && "rounded-lg border p-3",
              card && checked && "border-primary bg-primary/5 ring-1 ring-primary",
              card && !checked && !blocked && "hover:border-input hover:bg-muted/40",
              (disabled || blocked) && "cursor-not-allowed opacity-60",
            )}
          >
            <RadioGroupItem
              value={option.value}
              id={optionId}
              disabled={disabled || blocked}
              className="mt-0.5"
            />
            <span className="space-y-0.5">
              {/* Weight is what marks the current choice in text — it survives
                  greyscale and colour-blindness, which a tint does not. */}
              <span
                className={cn(
                  "block text-sm",
                  checked ? "font-semibold" : "font-medium",
                  checked && option.tone === "warning" && "text-warning",
                )}
              >
                {option.label}
              </span>
              {/* The blocker replaces the hint rather than joining it: two
                  explanations for one option is one too many, and while the
                  option cannot be chosen, why is the only thing that matters. */}
              {blocked ? (
                <span className="block text-xs font-medium text-warning">
                  {option.disabledReason}
                </span>
              ) : option.hint ? (
                <span className="block text-xs text-muted-foreground">
                  {option.hint}
                </span>
              ) : null}
            </span>
          </label>
        );
      })}
    </RadioGroup>
  );
}
