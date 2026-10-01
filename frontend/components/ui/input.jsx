import { ReasonTooltip, useDisabledReason } from "@/components/ui/reason-tooltip";
import { cn } from "@/lib/utils";

function Input({
  className,
  type,
  disabled = false,
  disabledReason,
  ...props
}) {
  const inheritedReason = useDisabledReason();
  // 14px everywhere, 16px on iOS only (`ios:`, see globals.css) to avoid
  // Safari's focus zoom. placeholder:text-sm keeps placeholders at 14px: the
  // zoom keys off the input's font-size, not ::placeholder.
  const control = (
    <input
      type={type}
      data-slot="input"
      disabled={disabled}
      className={cn(
        "h-9 w-full min-w-0 rounded-lg border border-input bg-transparent px-3 py-1 text-sm transition-colors outline-none file:inline-flex file:h-6 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-sm placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:bg-input/50 disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 ios:text-base dark:bg-input/30 dark:disabled:bg-input/80 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40",
        className
      )}
      {...props}
    />
  );

  // A parent already showing a tooltip here wins, to avoid two bubbles.
  if (disabled && inheritedReason?.handled && !disabledReason) return control;

  return (
    <ReasonTooltip
      className="inline-flex w-full"
      reason={
        disabled
          ? (disabledReason ?? inheritedReason?.reason)
          : null
      }
    >
      {control}
    </ReasonTooltip>
  );
}

export { Input }
