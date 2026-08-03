"use client"

import { useTheme } from "next-themes"
import { Toaster as Sonner } from "sonner";
import { CircleCheckIcon, InfoIcon, TriangleAlertIcon, OctagonXIcon, Loader2Icon } from "lucide-react"

const Toaster = ({
  ...props
}) => {
  const { theme = "system" } = useTheme()

  return (
    <Sonner
      theme={theme}
      // Radix sets `pointer-events: none` on <body> while a dialog is open and
      // re-enables it only inside the dialog. The toaster is a separate portal,
      // so its close button went dead whenever a modal was up — and a failure
      // toast is raised from a dialog more often than not.
      className="toaster group !pointer-events-auto"
      icons={{
        success: (
          <CircleCheckIcon className="size-4 text-success" />
        ),
        info: (
          <InfoIcon className="size-4 text-primary" />
        ),
        warning: (
          <TriangleAlertIcon className="size-4 text-warning" />
        ),
        error: (
          <OctagonXIcon className="size-4 text-destructive" />
        ),
        loading: (
          <Loader2Icon className="size-4 animate-spin text-muted-foreground" />
        ),
      }}
      style={
        {
          "--normal-bg": "var(--popover)",
          "--normal-text": "var(--popover-foreground)",
          "--normal-border": "var(--border)",
          "--border-radius": "var(--radius)"
        }
      }
      // A toast sits over the bottom-right of the page, which is where action
      // buttons live — so without a way to dismiss it you have to wait it out
      // before you can click what is underneath.
      closeButton
      toastOptions={{
        classNames: {
          // Room on the right so the message never runs under the close button.
          toast: "cn-toast !pr-10",
          // Sonner puts the close button on the top-left corner, revealed on
          // hover — neither works here: a touch screen cannot hover, and pinned
          // to a corner it read as a stray mark rather than a control. Centred
          // on the right edge, always visible, 28px so a thumb can hit it.
          // Centred with top/bottom-0 + auto margins rather than a translate:
          // Sonner sets its own `transform` to nudge the button into a corner,
          // which composes with any translate utility and leaves it ~10px high.
          closeButton:
            "!left-auto !right-2.5 !top-0 !bottom-0 !my-auto !size-7 !transform-none !rounded-md !border-0 !bg-transparent !text-foreground/60 hover:!bg-foreground/10 hover:!text-foreground !transition-colors",
          // Semantic tint per type — colored icon (above) + soft background and
          // matching border, so success/error read at a glance. Text stays on
          // the readable foreground token.
          //
          // The tint is MIXED INTO the popover colour rather than layered as an
          // alpha (`bg-success/10`): a translucent toast over a dark surface —
          // the log console — let the content behind show through and left the
          // dark toast text unreadable on it.
          success:
            "!bg-[color-mix(in_oklab,var(--success)_10%,var(--popover))] !border-success/25",
          error:
            "!bg-[color-mix(in_oklab,var(--destructive)_10%,var(--popover))] !border-destructive/25",
          warning:
            "!bg-[color-mix(in_oklab,var(--warning)_10%,var(--popover))] !border-warning/30",
          info: "!bg-[color-mix(in_oklab,var(--primary)_10%,var(--popover))] !border-primary/25",
        },
      }}
      {...props} />
  );
}

export { Toaster }
