import { useTranslations } from "next-intl";
import { Wrench } from "lucide-react";
import { RetryButton } from "@/components/ui/retry-button";
import { CopyButton } from "@/components/ui/copy-button";
import { FailureScreen, FailureFooterLabel } from "@/components/sections/failure-screen";

const COMMAND = "php artisan up";

/**
 * Shown instead of the panel when the API is in maintenance mode.
 *
 * Not destructive styling: nothing is broken. Unlike a rate limit it may not
 * clear on its own (an update that stops between `artisan down` and `artisan up`),
 * so the footer gives the command that ends it, on its own row with a copy button.
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
 * Full-screen form for layouts that own the viewport. Auth pages render the card
 * directly: their layout already centres it, and a nested min-h-svh squeezes it.
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
