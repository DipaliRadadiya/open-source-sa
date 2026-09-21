import { useTranslations } from "next-intl";
import { Wrench } from "lucide-react";
import { RetryButton } from "@/components/ui/retry-button";
import { CopyButton } from "@/components/ui/copy-button";
import { FailureScreen, FailureFooterLabel } from "@/components/sections/failure-screen";

const COMMAND = "php artisan up";

/**
 * Shown instead of the panel when the API is in maintenance mode.
 *
 * Not destructive styling, for the same reason as `RateLimited`: nothing is
 * broken and nothing was lost. But unlike a rate limit, this one does not
 * always clear on its own — an update that stops between `artisan down` and
 * `artisan up` leaves the server here forever — so the footer names that case
 * and gives the command that ends it.
 *
 * The command is its own row with a copy button, not a word inside a sentence:
 * the person reading it is about to type it into an SSH session, and picking a
 * command out of centred prose is the worst possible way to hand it over.
 */
export function PanelUnavailableCard() {
  const t = useTranslations("errors.unavailable");

  return (
    <FailureScreen
      icon={Wrench}
      tone="muted"
      title={t("title")}
      body={t("body")}
      action={<RetryButton />}
      footer={
        <>
          <FailureFooterLabel>{t("stuckLabel")}</FailureFooterLabel>
          <p className="text-sm leading-relaxed text-muted-foreground">{t("stuck")}</p>
          <div className="mt-3 flex items-center gap-2 rounded-lg border bg-background/60 px-3 py-2">
            <code className="min-w-0 flex-1 truncate font-mono text-xs">{COMMAND}</code>
            <CopyButton value={COMMAND} label={t("copyCommand")} />
          </div>
        </>
      }
    />
  );
}

/**
 * Full-screen form, for the layouts that own the whole viewport. The auth
 * pages render the card directly — their layout already centres it, and
 * nesting a second min-h-svh inside that one squeezes the box to a column.
 */
export function PanelUnavailable() {
  return (
    <div className="flex min-h-svh items-center justify-center p-6">
      <div className="w-full max-w-md">
        <PanelUnavailableCard />
      </div>
    </div>
  );
}
