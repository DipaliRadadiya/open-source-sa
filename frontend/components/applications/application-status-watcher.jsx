"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { getApplicationStatus, getContainerRedeploy } from "@/lib/api/applications";
import { containerRedeployFailure } from "@/lib/applications/settled";

const POLL_MS = 5000;
const LIMIT_MS = 30 * 60 * 1000;

export function ApplicationStatusWatcher({ id, status, redeploy = null }) {
  const router = useRouter();
  const t = useTranslations("applications.redeploy");
  const tc = useTranslations("applications.container");
  const inFlight = status === "pending" || status === "provisioning";
  const redeployId = redeploy?.in_flight ? redeploy.id : null;

  useEffect(() => {
    if (!inFlight) return;
    const started = Date.now();
    let live = true;
    const timer = window.setInterval(async () => {
      if (Date.now() - started > LIMIT_MS) {
        window.clearInterval(timer);
        return;
      }
      if (document.hidden) return;
      try {
        const next = await getApplicationStatus(id);
        if (live && next && next !== status) {
          window.clearInterval(timer);
          router.refresh();
        }
      } catch {
        // retry on the next poll
      }
    }, POLL_MS);
    return () => {
      live = false;
      window.clearInterval(timer);
    };
  }, [inFlight, id, status, router]);

  useEffect(() => {
    if (redeployId == null) return;
    const started = Date.now();
    let live = true;
    const timer = window.setInterval(async () => {
      if (Date.now() - started > LIMIT_MS) {
        window.clearInterval(timer);
        return;
      }
      if (document.hidden) return;
      try {
        const next = await getContainerRedeploy(id);
        if (!live || next?.in_flight) return;
        window.clearInterval(timer);
        router.refresh();
        if (next?.id !== redeployId) return;
        if (next.status === "failed") {
          toast.error(containerRedeployFailure(next, tc("pullFailed")) ?? t("failed"), {
            description: next.reference ? t("reference", { reference: next.reference }) : undefined,
          });
        } else {
          toast.success(t("succeeded"));
        }
      } catch {
        // retry on the next poll
      }
    }, POLL_MS);
    return () => {
      live = false;
      window.clearInterval(timer);
    };
  }, [redeployId, id, router, t, tc]);

  return null;
}
