import { useTranslations } from "next-intl";
import { Hourglass } from "lucide-react";
import { RetryButton } from "@/components/ui/retry-button";
import { FailureScreen } from "@/components/sections/failure-screen";

/**
 * Shown in place of the whole panel when the API is rate-limiting this client.
 * Not styled as destructive: nothing is broken, and waiting fixes it. Built on
 * `FailureScreen`, like the other whole-screen states.
 */
export function RateLimitedCard() {
  const t = useTranslations("errors.rateLimited");

  return (
    <FailureScreen
      icon={Hourglass}
      tone="muted"
      title={t("title")}
      body={t("body")}
      action={<RetryButton />}
    />
  );
}

export function RateLimited() {
  return (
    <div className="flex min-h-svh items-center justify-center p-6">
      <div className="w-full max-w-md">
        <RateLimitedCard />
      </div>
    </div>
  );
}
