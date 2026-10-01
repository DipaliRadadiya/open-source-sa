import { useTranslations } from "next-intl";
import { KeyRound, TriangleAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";

// The only time this key is readable. Inline, not a dialog: Escape would dismiss it by reflex.
export function KeyReveal({ token, onDone }) {
  const t = useTranslations("central");

  return (
    <div data-slot="notice" className="space-y-4 rounded-xl border border-warning/40 bg-warning/5 p-4">
      <div className="flex items-start gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-warning/15 text-warning">
          <KeyRound className="size-5" aria-hidden />
        </span>
        <div className="min-w-0 space-y-1">
          <p className="font-medium">{t("reveal.title")}</p>
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
