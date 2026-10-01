import { cn } from "@/lib/utils";

/**
 * The shared skeleton for the two whole-screen failures: "the panel is updating"
 * and "a request failed".
 *
 * Three groups, with larger gaps between than within:
 * 1. what happened  icon, heading, one sentence; centred.
 * 2. the way out    the retry control, alone.
 * 3. what to do     below a rule, left-aligned and labelled as reference
 *                   material (it contains commands and hostnames).
 *
 * Unlike `FailurePanel`, this takes a tone: updating is a warning, not an error,
 * and must not be styled as one.
 */
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
        // A solid surface with a shadow (`bg-card` + `shadow-xl shadow-black/5`, as the
        // login card uses); a translucent fill had no edge on the auth gradient. Tone is
        // carried by the icon and border tint.
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

/**
 * Small uppercase heading over a footer block, so troubleshooting text does not
 * read as part of the explanation.
 */
export function FailureFooterLabel({ children }) {
  return (
    <p className="mb-2 text-[0.6875rem] font-medium tracking-wide text-muted-foreground uppercase">
      {children}
    </p>
  );
}
