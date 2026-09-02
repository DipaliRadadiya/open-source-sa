import { useTranslations } from "next-intl";
import { KeyRound, TriangleAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";

/**
 * The one and only time this key is ever readable.
 *
 * Inline on the page rather than in a dialog. Every product surveyed that gets
 * this right — GitHub, Stripe, Cloudflare, Coolify — reveals the secret in
 * place; only AWS uses a modal, and a modal is the wrong shape here twice over:
 * Escape dismisses it by reflex, and the fix for that (a dialog you cannot
 * close) turns a failed copy into a trap.
 *
 * Nothing blocks leaving, for the same reason nobody else blocks it. The
 * guard is that the warning is stated plainly and the value is one click from
 * the clipboard.
 */
export function KeyReveal({ token, onDone }) {
  const t = useTranslations("central");

  return (
    <div className="space-y-4 rounded-xl border border-warning/40 bg-warning/5 p-4">
      <div className="flex items-start gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-warning/15 text-warning">
          <KeyRound className="size-5" aria-hidden />
        </span>
        <div className="min-w-0 space-y-1">
          <p className="font-medium">{t("reveal.title")}</p>
          {/* The whole point of the screen, in the strongest place on it. */}
          <p className="flex items-start gap-1.5 text-sm text-warning">
            <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
            <span>{t("reveal.warning")}</span>
          </p>
        </div>
      </div>

      <div className="flex items-start gap-1.5 rounded-lg border bg-background px-3 py-2">
        <code className="min-w-0 flex-1 font-mono text-xs break-all">{token}</code>
        <CopyButton value={token} label={t("reveal.copy")} />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-xs text-muted-foreground">{t("reveal.next")}</p>
        <Button type="button" variant="outline" size="sm" onClick={onDone}>
          {t("reveal.done")}
        </Button>
      </div>
    </div>
  );
}
