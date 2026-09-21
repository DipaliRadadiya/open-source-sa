import { cn } from "@/lib/utils";

/**
 * The shared skeleton for the two whole-screen failures — "the panel is
 * updating" and "a request failed".
 *
 * Krishna: "need to improve this ui. everything looks merged."
 *
 * He was right, and the cause was uniform spacing: icon, heading, sentence,
 * button and a troubleshooting note all sat in one `gap-4` column, centred,
 * at almost the same size. Nothing told the eye where one thought ended and
 * the next began, so it read as a paragraph with a button in it.
 *
 * Three groups now, with the gaps between them larger than the gaps inside:
 *
 *   1. what happened  — icon, heading, one sentence. Centred, because it is
 *                       the thing you read first and nothing competes with it.
 *   2. the way out    — the retry control, alone.
 *   3. what to do     — separated by a rule, LEFT-aligned and labelled, so it
 *                       reads as reference material rather than more prose.
 *                       Left-aligned on purpose: it contains commands and
 *                       hostnames, and centred monospace is hard to scan.
 *
 * Unlike `FailurePanel`, which is deliberately fixed because four boundaries
 * had drifted apart, this one takes a tone: its two call sites are a warning
 * and a failure, and that difference is the point — the panel updating is not
 * an error and must not be dressed as one.
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
        // A SOLID surface with a shadow, not a tinted wash.
        //
        // Krishna: "this card ui looks merged with background". It was: the
        // card filled itself with `bg-muted/30` — translucent grey — and sat
        // on the auth layout's blue-tinted gradient with a hairline border.
        // At 30% opacity the fill and the page were within a few percent of
        // each other, so the card had no edge.
        //
        // `bg-card` + `shadow-xl shadow-black/5` is what the login card two
        // metres away already uses. The same screen should not have two ideas
        // about what a card is.
        //
        // The tone survives in the icon and the border tint, which is where it
        // belongs — colour that means something, rather than colour as a fill.
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

      {/* A <p>, not an <h1>: every other failure screen in the panel
          (`FailurePanel`, `RateLimited`) does the same, `role="alert"` above
          already announces the whole card, and `check-page-header` reserves
          hand-rolled headings for real pages. */}
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
 * The small uppercase heading over a footer block. It is what stops the
 * troubleshooting text reading as a fourth sentence of the explanation.
 */
export function FailureFooterLabel({ children }) {
  return (
    <p className="mb-2 text-[0.6875rem] font-medium tracking-wide text-muted-foreground uppercase">
      {children}
    </p>
  );
}
