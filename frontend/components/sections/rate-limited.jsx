import { useTranslations } from "next-intl";
import { Hourglass } from "lucide-react";
import { RetryButton } from "@/components/ui/retry-button";

/**
 * Shown in place of the whole panel when the API is turning us away for asking
 * too often. Deliberately not styled as destructive: nothing is broken and
 * nothing was changed — waiting a moment fixes it.
 */
export function RateLimited() {
  const t = useTranslations("errors.rateLimited");

  return (
    <div className="flex min-h-svh items-center justify-center p-6">
      <div
        role="alert"
        className="flex w-full max-w-md flex-col items-center gap-4 rounded-xl border bg-muted/30 px-6 py-12 text-center"
      >
        <span className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <Hourglass className="size-5" />
        </span>
        <div className="space-y-1">
          <p className="font-medium">{t("title")}</p>
          <p className="text-sm text-muted-foreground">{t("body")}</p>
        </div>
        <RetryButton />
      </div>
    </div>
  );
}
