import * as React from "react"
import { Label as LabelPrimitive } from "radix-ui"
import { useTranslations } from "next-intl"

import { cn } from "@/lib/utils"
import { InfoHint } from "@/components/ui/info-hint"

// Popover, not tooltip: Radix tooltips don't open on touch.
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

function LabelHint({ children }) {
  const t = useTranslations("common")
  return (
    <InfoHint label={t("whatIsThis")} className="-my-[3px]">
      {children}
    </InfoHint>
  )
}

export { Label, LabelHint }
