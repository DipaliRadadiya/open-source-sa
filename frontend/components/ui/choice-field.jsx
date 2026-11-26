import { useId } from "react";
import { cn } from "@/lib/utils";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

export function ChoiceField({ value, onChange, options, disabled, name, className, variant, "aria-label": ariaLabel }) {
  const id = useId();
  const card = variant === "card";

  return (
    <RadioGroup
      value={value}
      onValueChange={onChange}
      disabled={disabled}
      name={name}
      aria-label={ariaLabel}
      className={cn("gap-1.5", className)}
    >
      {options.map((option) => {
        const checked = option.value === value;
        const blocked = Boolean(option.disabledReason);
        const optionId = `${id}-${option.value}`;
        return (
          <ReasonTooltip
            key={option.value}
            reason={option.disabledReason ?? null}
            className={cn("block", card && "h-full")}
          >
          <label
            htmlFor={optionId}
            className={cn(
              "flex cursor-pointer items-start gap-3 rounded-md px-2 py-1.5 transition-colors",
              !checked && !blocked && !card && "hover:bg-muted/50",
              card && "h-full rounded-lg border p-3",
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
              aria-labelledby={`${optionId}-label`}
              aria-describedby={blocked || option.hint ? `${optionId}-hint` : undefined}
            />
            {option.icon ? (
              <span
                className={cn(
                  "mt-px flex size-7 shrink-0 items-center justify-center rounded-md transition-colors",
                  checked ? "bg-primary/10 text-primary" : "bg-muted text-muted-foreground",
                )}
              >
                <option.icon className="size-4" aria-hidden />
              </span>
            ) : null}
            <span className="space-y-0.5">
              <span
                id={`${optionId}-label`}
                className={cn(
                  "block text-sm",
                  checked ? "font-semibold" : "font-medium",
                  checked && option.tone === "warning" && "text-warning",
                )}
              >
                {option.label}
              </span>
              {blocked ? (
                <span id={`${optionId}-hint`} className="block text-xs font-medium text-warning">
                  {option.disabledReason}
                </span>
              ) : option.hint ? (
                <span id={`${optionId}-hint`} className="block text-xs text-muted-foreground">
                  {option.hint}
                </span>
              ) : null}
            </span>
          </label>
          </ReasonTooltip>
        );
      })}
    </RadioGroup>
  );
}
