"use client";

import { useTranslations } from "next-intl";
import Link from "@/components/ui/app-link";
import { Caution } from "@/components/ui/caution";
import { CopyButton } from "@/components/ui/copy-button";
import {
  useReloadPending,
  useReloadPendingActions,
} from "@/components/applications/reload-pending-provider";

// Stays until a web server restart or reload from this browser succeeds:
// the API keeps no "not applied" marker to read back.
export function ReloadPendingNotice({ appId, className }) {
  const t = useTranslations("applications.reloadPending");
  const record = useReloadPending(appId);
  const { canManageServices } = useReloadPendingActions();

  if (!record) return null;

  return (
    <Caution size="md" className={className}>
      <div role="status" className="space-y-2">
        <p>
          <span className="font-medium">{t("title")}</span> {t("body")}{" "}
          {canManageServices ? (
            <Link
              href="/services"
              prefetch={false}
              className="font-medium underline underline-offset-2"
            >
              {t("openServices")}
            </Link>
          ) : (
            t("askAdmin")
          )}
        </p>
        {record.r ? (
          <p className="flex min-w-0 items-center gap-1 text-xs text-muted-foreground">
            <span className="shrink-0">{t("reference")}</span>
            <code className="min-w-0 font-mono text-foreground [overflow-wrap:anywhere]">
              {record.r}
            </code>
            <CopyButton value={record.r} label={t("copyReference")} />
          </p>
        ) : null}
      </div>
    </Caution>
  );
}
