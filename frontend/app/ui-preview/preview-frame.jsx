import Link from "next/link";
import { TooltipProvider } from "@/components/ui/tooltip";

/**
 * The chrome around a fixture-rendered screen.
 *
 * Deliberately unmistakable: a preview that could be confused for the real
 * panel would be worse than no preview, because someone would eventually press
 * a button and wonder why nothing happened.
 */
export function PreviewFrame({ title, states, current, base, children }) {
  return (
    // The real panel gets this from the `(app)` layout. Without it any screen
    // containing a tooltip — a disabled Save explaining itself, for one —
    // throws on render rather than degrading.
    <TooltipProvider delayDuration={0}>
      <div className="min-h-svh bg-muted/20">
        <div className="border-b bg-warning/10">
          <div className="mx-auto flex max-w-5xl flex-wrap items-center gap-x-3 gap-y-1 px-6 py-2 text-sm">
            <span className="font-medium">UI preview — {title}</span>
            <span className="text-muted-foreground">
              Fixture data. Buttons will not work; the API is not involved.
            </span>
            <Link
              href="/ui-preview"
              className="ml-auto text-primary hover:underline"
            >
              All previews
            </Link>
          </div>
        </div>

        <div className="mx-auto max-w-5xl space-y-4 px-6 py-6">
          <div className="flex flex-wrap gap-2">
            {Object.entries(states).map(([key, state]) => (
              <Link
                key={key}
                href={`${base}?state=${key}`}
                className={
                  key === current
                    ? "rounded-md bg-primary px-2.5 py-1 text-xs font-medium text-primary-foreground"
                    : "rounded-md border bg-background px-2.5 py-1 text-xs transition-colors hover:bg-muted"
                }
              >
                {state.label}
              </Link>
            ))}
          </div>

          {children}
        </div>
      </div>
    </TooltipProvider>
  );
}
