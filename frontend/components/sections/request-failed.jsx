import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { RetryButton } from "@/components/ui/retry-button";
import { FailureScreen, FailureFooterLabel } from "@/components/sections/failure-screen";

// The request line sits in a native `<details>`: no hydration on a failure screen.
// `trace`/`file`/`line` are never carried; only their presence is reported (debug mode).
export function RequestFailedCard({ kind, method, path, host, status, serverMessage = null, debug = false }) {
  const t = useTranslations("errors");
  const values = { host: host ?? "", status: status ?? "", path };

  return (
    <FailureScreen
      icon={TriangleAlert}
      title={t(`why.${kind}.title`)}
      body={t(`why.${kind}.body`, values)}
      action={<RetryButton />}
      footer={
        <>
          {/* Quoted and attributed so they are not mistaken for the panel's. */}
          {serverMessage ? (
            <div className="mb-5">
              <FailureFooterLabel>{t("request.serverSaid")}</FailureFooterLabel>
              <blockquote className="border-l-2 border-border py-0.5 pl-3 text-sm leading-relaxed text-foreground">
                {serverMessage}
              </blockquote>
              {debug ? (
                <p className="mt-2 text-xs text-amber-700 dark:text-amber-500">
                  {t("request.debugWarning")}
                </p>
              ) : null}
            </div>
          ) : null}

          <FailureFooterLabel>{t("whatToDo")}</FailureFooterLabel>
          <p className="text-sm leading-relaxed text-muted-foreground">
            {t(`why.${kind}.next`, values)}
          </p>

          <details className="group mt-4">
            <summary className="cursor-pointer list-none text-xs text-muted-foreground underline decoration-dotted underline-offset-4 hover:text-foreground">
              {t("request.details")}
            </summary>
            <dl className="mt-2 space-y-1 rounded-lg border bg-background/60 px-3 py-2 font-mono text-xs">
              <div className="flex items-baseline justify-between gap-3">
                <dt className="sr-only">{t("request.endpoint")}</dt>
                <dd className="truncate text-foreground">
                  <span className="text-muted-foreground">{method}</span> {path}
                </dd>
                <dt className="sr-only">{t("request.status")}</dt>
                <dd
                  className={
                    status === null
                      ? "shrink-0 text-muted-foreground"
                      : "shrink-0 text-destructive"
                  }
                >
                  {status === null ? t("request.noResponse") : status}
                </dd>
              </div>
              {host ? (
                <div>
                  <dt className="sr-only">{t("request.host")}</dt>
                  <dd className="truncate text-muted-foreground">{host}</dd>
                </div>
              ) : null}
            </dl>
          </details>
        </>
      }
    />
  );
}

export function RequestFailed(props) {
  return (
    <div className="flex min-h-svh items-center justify-center p-6">
      <div className="w-full max-w-md">
        <RequestFailedCard {...props} />
      </div>
    </div>
  );
}
