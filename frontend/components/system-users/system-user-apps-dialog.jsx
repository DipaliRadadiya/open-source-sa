"use client";

import { useState, useEffect } from "react";
import { Globe } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { FormModal } from "@/components/ui/form-modal";
import { getSystemUserDetail } from "@/lib/api/system-users";

function statusVariant(status) {
  const s = (status ?? "").toLowerCase();
  if (/(active|running|online|live|deployed)/.test(s)) return "success";
  if (/(stop|error|fail|offline|down|inactive)/.test(s)) return "destructive";
  return "secondary";
}

export function SystemUserAppsDialog({ user, open, onOpenChange }) {
  const t = useTranslations("systemUsers");
  const minimal = user?.applications ?? []; // list gives id + name only
  const [apps, setApps] = useState(null); // null = loading

  // Fetch full detail on open (domain/status live on the detail endpoint).
  // Falls back to the minimal list data if the fetch fails.
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, user?.id]);

  function handleOpenChange(next) {
    if (!next) setApps(null);
    onOpenChange?.(next);
  }

  const skeletonCount = Math.max(minimal.length, 2);

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
      {apps === null ? (
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
          ) : apps.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {t("detail.noApplications")}
            </p>
          ) : (
            <ul className="grid gap-2 sm:grid-cols-2">
              {apps.map((app) => (
                <li
                  key={app.id}
                  className="flex items-center justify-between gap-2 rounded-lg border p-3"
                >
                  <div className="flex min-w-0 items-start gap-2.5">
                    <Globe className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium">{app.name}</p>
                      {app.domain ? (
                        <p className="truncate text-xs text-muted-foreground">
                          {app.domain}
                        </p>
                      ) : null}
                    </div>
                  </div>
                  {app.status ? (
                    <Badge
                      variant={statusVariant(app.status)}
                      className="shrink-0 font-normal capitalize"
                    >
                      {app.status}
                    </Badge>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
    </FormModal>
  );
}
