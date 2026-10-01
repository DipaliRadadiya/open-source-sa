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
      // Radix sets `pointer-events: none` on <body> while a dialog is open;
      // the toaster portal must stay clickable.
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
      // Toasts can cover action buttons, so they must be dismissable.
      closeButton
      toastOptions={{
        classNames: {
          // Room on the right for the close button.
          toast: "cn-toast !pr-10",
          // Always visible (touch cannot hover). Auto margins, since Sonner's own
          // `transform` would compose with a translate.
          closeButton:
            "!left-auto !right-2.5 !top-0 !bottom-0 !my-auto !size-7 !transform-none !rounded-md !border-0 !bg-transparent !text-foreground/60 hover:!bg-foreground/10 hover:!text-foreground !transition-colors",
          // Per-type tint mixed into the popover colour, not an alpha: a
          // translucent toast is unreadable over dark surfaces.
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
