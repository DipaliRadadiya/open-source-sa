import { useState, useEffect } from "react";
import Link from "@/components/ui/app-link";
import { Globe, AppWindow, ChevronRight } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { FormModal } from "@/components/ui/form-modal";
import { getSystemUserDetail } from "@/lib/api/system-users";
import { ApplicationStatusDot } from "@/components/applications/application-status-badge";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { HoverHint } from "@/components/ui/hover-hint";

const SEARCH_FROM = 9;

export function SystemUserAppsDialog({ user, open, onOpenChange }) {
  const t = useTranslations("systemUsers");
  const ta = useTranslations("common.applicationContext");
  const [query, setQuery] = useState("");
  const minimal = user?.applications ?? [];
  const [apps, setApps] = useState(null);
  const [detailFailed, setDetailFailed] = useState(false);

  useEffect(() => {
    if (!open || !user) return;
    let active = true;
    getSystemUserDetail(user.id)
      .then(
        (res) =>
          active && setApps(res.data?.system_user?.applications ?? minimal),
      )
      .catch(() => {
        if (!active) return;
        setApps(minimal);
        setDetailFailed(true);
      });
    return () => {
      active = false;
    };
    // `minimal` is excluded: it is only the failure fallback, derived from the same
    // user prop, and would refetch on every parent render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, user?.id]);

  function handleOpenChange(next) {
    if (!next) {
      setApps(null);
      setDetailFailed(false);
      setQuery("");
    }
    onOpenChange?.(next);
  }

  const skeletonCount = Math.max(minimal.length, 2);
  const loading = apps === null;
  const shown = apps ?? minimal;
  const term = query.trim().toLowerCase();
  const matches = term
    ? shown.filter((app) => app.name.toLowerCase().includes(term) || (app.domain ?? "").toLowerCase().includes(term))
    : shown;

  return (
    <FormModal
      open={open}
      onOpenChange={handleOpenChange}
      icon={Globe}
      title={
        <>
          {`${t("apps")} — ${user?.username ?? ""}`}
          {shown.length ? <span className="ms-2 text-sm font-normal tabular-nums text-muted-foreground">{shown.length}</span> : null}
        </>
      }
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
            <ul className="divide-y overflow-hidden rounded-lg border">
              {Array.from({ length: skeletonCount }).map((_, i) => (
                <li
                  key={i}
                  className="flex items-center gap-3 px-3 py-2.5"
                >
                  <Skeleton className="size-8 shrink-0 rounded-md" />
                  <div className="flex-1 space-y-1.5">
                    <Skeleton className="h-3.5 w-20" />
                    <Skeleton className="h-3 w-28" />
                  </div>
                </li>
              ))}
            </ul>
          ) : shown.length === 0 ? (
            <EmptyState compact icon={AppWindow} badge={null} title={t("detail.noApplications")} />
          ) : (
            <div className="space-y-3">
              {detailFailed ? <p role="status" className="text-xs text-muted-foreground">{t("appsDetailFailed")}</p> : null}
              {shown.length >= SEARCH_FROM ? (
                <LocalSearchInput value={query} onChange={setQuery} placeholder={ta("searchPlaceholder")} className="sm:max-w-none" />
              ) : null}
              {matches.length === 0 ? (
                <p className="py-6 text-center text-sm text-muted-foreground">{ta("noMatch", { query: query.trim() })}</p>
              ) : (
                <ul className="divide-y overflow-hidden rounded-lg border">
                  {matches.map((app) => (
                    <li key={app.id}>
                      <Link
                        href={`/applications/${app.id}`}
                        prefetch={false}
                        onClick={() => handleOpenChange(false)}
                        className="group flex min-w-0 items-center gap-3 px-3 py-2.5 outline-none transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring/50"
                      >
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-md border bg-card">
                          {app.site_type ? <SiteTypeLogo name={app.site_type} size="h-4 w-4" /> : <Globe className="size-4 text-muted-foreground" />}
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="flex min-w-0 items-start justify-between gap-3">
                            <HoverHint content={app.name}>
                              <span className="line-clamp-2 text-sm font-medium [overflow-wrap:anywhere]">{app.name}</span>
                            </HoverHint>
                            {app.status ? <ApplicationStatusDot application={app} className="mt-0.5 shrink-0" /> : null}
                          </span>
                          {app.domain ? (
                            <span className="block break-all font-mono text-xs text-muted-foreground">{app.domain}</span>
                          ) : loading ? (
                            <Skeleton className="mt-1.5 h-3 w-28" />
                          ) : null}
                        </span>
                        <ChevronRight className="hidden size-4 shrink-0 text-muted-foreground/60 transition-colors group-hover:text-foreground sm:block" aria-hidden />
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
    </FormModal>
  );
}
