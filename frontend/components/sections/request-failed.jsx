import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { RetryButton } from "@/components/ui/retry-button";
import { FailureScreen, FailureFooterLabel } from "@/components/sections/failure-screen";

/**
 * Krishna: "not like this showing api and all. i want to see proper error
 * message why this error is getting."
 *
 * The card leads with the cause in plain words. Each `kind` gets a real
 * explanation instead of a shared "try again":
 *
 *   network    nothing answered at all — stopped service, or a blocked port
 *   server     the API answered with an error; the reason is in ITS log
 *   forbidden  the API refused this account
 *   notFound   the endpoint is missing, which usually means the panel and the
 *              API are different versions
 *
 * The request line is real evidence — the fetch happens during SSR, so it
 * leaves no Network tab row and this is the only record that will ever exist —
 * but it is a support artefact, so it sits in a closed `<details>` under the
 * advice. Native `<details>`, not state: this renders inside a failure, and a
 * screen that has already lost one thing should not need hydration to open.
 *
 * The server's own `message` IS shown, and leads the footer — Krishna: "why we
 * cannot see actual message instead of showing just Your server returned an
 * error". It is the reason; our sentence is only the category. `trace`, `file`
 * and `line` are still never carried; their PRESENCE is reported instead, as a
 * warning that the server is in debug mode on a page anyone can reach.
 */
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
          {/* The server's OWN words come first, above our category. It knows
              why; we only know what kind of thing happened. Quoted and
              attributed so nobody mistakes it for the panel talking. */}
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
