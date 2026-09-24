import * as React from "react"
import { Label as LabelPrimitive } from "radix-ui"
import { useTranslations } from "next-intl"

import { cn } from "@/lib/utils"
import { InfoHint } from "@/components/ui/info-hint"

/**
 * `hint` explains a technical field, behind a "?" the reader has to ask for.
 *
 * It lives here rather than only on FormLabel because half the panel's
 * dialogs — delete, restore, SSL, adopt — label their controls with a plain
 * Label and never go near react-hook-form. Putting it on both means one "?",
 * one size, one behaviour, wherever the field happens to live.
 *
 * The component behind it is a Popover, not a Tooltip: a Radix tooltip opens
 * on hover and focus only, so on a phone the explanation is unreachable.
 */
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

/**
 * The trigger needs a name of its own — alone it is an icon, and a screen
 * reader would announce nothing but "button". The explanation itself cannot be
 * that name: these run to a sentence or two, and hearing the whole thing read
 * out before you asked for it defeats the point of hiding it behind a control.
 */
function LabelHint({ children }) {
  const t = useTranslations("common")
  // The trigger keeps its 20px target but not its 20px of line height: a
  // label with a hint was 6px taller than one without, so of two fields side
  // by side the one with the ⓘ sat lower.
  return (
    <InfoHint label={t("whatIsThis")} className="-my-[3px]">
      {children}
    </InfoHint>
  )
}

/*
 * Exported for the one case the `hint` prop cannot serve: a label that also
 * prints its php.ini directive. The ⓘ goes last, so on a label long enough to
 * wrap it broke away from the text and sat alone on the next line. Rendered as
 * a child it can be tied to the directive and wrap with it.
 */
export { Label, LabelHint }
