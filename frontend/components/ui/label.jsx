import * as React from "react"
import { Label as LabelPrimitive } from "radix-ui"
import { useTranslations } from "next-intl"

import { cn } from "@/lib/utils"
import { InfoHint } from "@/components/ui/info-hint"

// A Popover, not a tooltip: Radix tooltips never open on touch.
function Label({
  className,
  hint,
  children,
  ...props
}) {
  return (
    <LabelPrimitive.Root
      data-slot="label"
      className={cn(
        "flex items-center gap-2 text-sm leading-none font-medium select-none group-data-[disabled=true]:pointer-events-none group-data-[disabled=true]:opacity-50 peer-disabled:cursor-not-allowed peer-disabled:opacity-50",
        className
      )}
      {...props}>
      {children}
      {hint ? <LabelHint>{hint}</LabelHint> : null}
    </LabelPrimitive.Root>
  );
}

/** The icon trigger gets a short accessible name, not the full explanation. */
function LabelHint({ children }) {
  const t = useTranslations("common")
  // Negative margin keeps the 20px target without making the label taller.
  return (
    <InfoHint label={t("whatIsThis")} className="-my-[3px]">
      {children}
    </InfoHint>
  )
}

/* LabelHint is for labels that must place the ⓘ themselves. */
export { Label, LabelHint }
