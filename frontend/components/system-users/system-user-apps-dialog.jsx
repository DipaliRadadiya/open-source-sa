import { useState, useEffect } from "react";
import Link from "@/components/ui/app-link";
import { Globe } from "lucide-react";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { FormModal } from "@/components/ui/form-modal";
import { getSystemUserDetail } from "@/lib/api/system-users";
import { ApplicationStatusBadge } from "@/components/applications/application-status-badge";

export function SystemUserAppsDialog({ user, open, onOpenChange }) {
  const t = useTranslations("systemUsers");
  const minimal = user?.applications ?? []; // list gives id + name only
  const [apps, setApps] = useState(null); // null = loading

  // Fetch full detail on open (domain/status live on the detail endpoint). Falls
  // back to the minimal list data if the fetch fails.
  useEffect(() => {
    if (!open || !user) return;
    let active = true;
    getSystemUserDetail(user.id)
      .then(
        (res) =>
          active && setApps(res.data?.system_user?.applications ?? minimal),
      )
      .catch(() => active && setApps(minimal));
    return () => {
      active = false;
    };
    // `minimal` is excluded: it is only the failure fallback, derived from the same
    // user prop, and would refetch on every parent render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, user?.id]);

  function handleOpenChange(next) {
    if (!next) setApps(null);
    onOpenChange?.(next);
  }

  const skeletonCount = Math.max(minimal.length, 2);
  // Names come from the list row and show at once; only domain and status wait for
  // the detail request.
  const loading = apps === null;
  const shown = apps ?? minimal;

  return (
    <FormModal
      open={open}
      onOpenChange={handleOpenChange}
      icon={Globe}
      title={`${t("apps")} — ${user?.username ?? ""}`}
      description={t("appsSubtitle")}
      footer={
        <Button
          type="button"
          variant="outline"
          onClick={() => handleOpenChange(false)}
        >
          {t("close")}
        </Button>
      }
    >
      {loading && shown.length === 0 ? (
            <ul className="grid gap-2 sm:grid-cols-2">
              {Array.from({ length: skeletonCount }).map((_, i) => (
                <li
                  key={i}
                  className="flex items-center gap-2.5 rounded-lg border p-3"
                >
                  <Skeleton className="size-4 shrink-0 rounded" />
                  <div className="flex-1 space-y-1.5">
                    <Skeleton className="h-3.5 w-20" />
                    <Skeleton className="h-3 w-28" />
                  </div>
                </li>
              ))}
            </ul>
          ) : shown.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {t("detail.noApplications")}
            </p>
          ) : (
            <ul className="grid gap-2 sm:grid-cols-2">
              {shown.map((app) => (
                // min-w-0: a grid item is as wide as its content, so a long domain pushed the
                // status off a phone screen.
                <li key={app.id} className="min-w-0">
                  <Link
                    href={`/applications/${app.id}`}
                    prefetch={false}
                    onClick={() => handleOpenChange(false)}
                    className="flex items-center justify-between gap-2 rounded-lg border p-3 outline-none transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring/50"
                  >
                    <div className="flex min-w-0 items-start gap-2.5">
                      <Globe className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                      <div className="min-w-0">
                        {/* Wraps: truncated, "QA Blog" and "QA Blog (Staging)" read the same on a phone. */}
                        <p className="text-sm font-medium [overflow-wrap:anywhere]">{app.name}</p>
                        {app.domain ? (
                          <p className="truncate text-xs text-muted-foreground">
                            {app.domain}
                          </p>
                        ) : loading ? (
                          <Skeleton className="mt-1.5 h-3 w-28" />
                        ) : null}
                      </div>
                    </div>
                    {/* The same badge and words as the Applications page. */}
                    {app.status ? (
                      <span className="shrink-0">
                        <ApplicationStatusBadge application={app} />
                      </span>
                    ) : null}
                  </Link>
                </li>
              ))}
            </ul>
          )}
    </FormModal>
  );
}
