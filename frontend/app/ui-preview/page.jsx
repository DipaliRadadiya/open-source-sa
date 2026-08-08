import Link from "next/link";
import { FAIL2BAN_STATES, PHP_STATES } from "./fixtures";

export const metadata = { title: "UI previews" };

/**
 * Screens rendered from fixtures, with no API behind them.
 *
 * Built on 2026-08-08, when the backend had been returning 500 for a day and
 * every new screen was unreviewable. The panel proper lives under `(app)`,
 * whose layout needs a session; this route sits outside it and the root layout
 * already falls back to default branding, so these render with the API dead.
 *
 * It also shows states that are rare or destructive on a real server —
 * over-committed memory, an attack in progress, being banned from your own
 * site — which is worth having even once the API is back.
 */
export default function UiPreviewIndex() {
  const screens = [
    { href: "/ui-preview/php", title: "PHP settings", states: PHP_STATES },
    { href: "/ui-preview/fail2ban", title: "Attack protection", states: FAIL2BAN_STATES },
  ];

  return (
    <div className="mx-auto max-w-3xl space-y-6 px-6 py-10">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">UI previews</h1>
        <p className="text-sm text-muted-foreground">
          Screens rendered from fixture data, so they can be reviewed without a working API.
          Nothing here talks to the backend and no button will do anything.
        </p>
      </div>

      {screens.map((screen) => (
        <div key={screen.href} className="rounded-xl border bg-background p-4">
          <h2 className="font-medium">{screen.title}</h2>
          <div className="mt-2 flex flex-wrap gap-2">
            {Object.entries(screen.states).map(([key, state]) => (
              <Link
                key={key}
                href={`${screen.href}?state=${key}`}
                className="rounded-md border px-2.5 py-1 text-xs transition-colors hover:bg-muted"
              >
                {state.label}
              </Link>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
