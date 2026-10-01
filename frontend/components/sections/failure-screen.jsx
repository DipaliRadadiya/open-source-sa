import { cn } from "@/lib/utils";

// Takes a tone, unlike `FailurePanel`: updating is a warning and must not be styled as an error.
export function FailureScreen({
  icon: Icon,
  tone = "destructive",
  title,
  body,
  action = null,
  footer = null,
}) {
  const muted = tone === "muted";

  return (
    <div
      role="alert"
      className={cn(
        // Solid surface, as the login card: a translucent fill had no edge on the auth gradient.
        "flex w-full flex-col items-center rounded-xl border bg-card px-6 py-10 text-center shadow-xl shadow-black/5",
        muted ? "border-border" : "border-destructive/25",
      )}
    >
      <span
        className={cn(
          "flex size-14 items-center justify-center rounded-full",
          muted ? "bg-muted text-muted-foreground" : "bg-destructive/10 text-destructive",
        )}
      >
        <Icon className="size-6" />
      </span>

      {/* A <p>, not an <h1>, like other failure screens: `role="alert"` already
          announces the card, and `check-page-header` reserves headings for real pages. */}
      <p className="mt-5 text-lg font-semibold tracking-tight text-balance">{title}</p>
      <p className="mt-2 max-w-xs text-sm leading-relaxed text-muted-foreground text-pretty">
        {body}
      </p>

      {action ? <div className="mt-6">{action}</div> : null}

      {footer ? <div className="mt-7 w-full border-t pt-5 text-left">{footer}</div> : null}
    </div>
  );
}

export function FailureFooterLabel({ children }) {
  return (
    <p className="mb-2 text-[0.6875rem] font-medium tracking-wide text-muted-foreground uppercase">
      {children}
    </p>
  );
}
