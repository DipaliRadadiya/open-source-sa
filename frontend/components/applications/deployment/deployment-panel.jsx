"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { deployApplication } from "@/lib/api/applications";
import { readApplication } from "@/lib/api/deployment";
import { applicationSchema } from "@/lib/schemas/application";
import { apiMessage } from "@/lib/api/error-message";
import { DeployCard } from "@/components/applications/deployment/deploy-card";
import { WebhookCard } from "@/components/applications/deployment/webhook-card";

// A deploy flips status to "provisioning" while it runs; poll the resource so
// steps[] and the commit/timestamp update in place without leaving the page.
const POLL_MS = 2500;

export function DeploymentPanel({ application: initial, providers, canManage }) {
  const t = useTranslations("applications.deployment");
  const [application, setApplication] = useState(initial);
  const [deploying, setDeploying] = useState(false);
  const pollRef = useRef(null);

  const stopPoll = useCallback(() => {
    if (pollRef.current) {
      clearInterval(pollRef.current);
      pollRef.current = null;
    }
  }, []);

  useEffect(() => () => stopPoll(), [stopPoll]);

  const refresh = useCallback(async () => {
    try {
      const { data } = await readApplication(application.id);
      const parsed = applicationSchema.safeParse(data?.application);
      if (parsed.success) {
        setApplication(parsed.data);
        return parsed.data;
      }
    } catch {
      // Transient poll error — keep the last good state and try again.
    }
    return null;
  }, [application.id]);

  const deploy = useCallback(async () => {
    const before = application.last_deployed_at;
    setDeploying(true);
    try {
      await deployApplication(application.id);
      toast.info(t("deploy.started"));
      stopPoll();
      pollRef.current = setInterval(async () => {
        const next = await refresh();
        if (!next) return;
        // Back to a settled state — a failed redeploy leaves the site "active"
        // with failed_step set, so read that, not the status, for the verdict.
        if (next.status === "active" || next.status === "failed") {
          stopPoll();
          setDeploying(false);
          if (next.failed_step) {
            toast.error(t("deploy.failedAt", { step: next.failed_step }));
          } else if (next.last_deployed_at !== before) {
            toast.success(t("deploy.done"));
          }
        }
      }, POLL_MS);
    } catch (error) {
      setDeploying(false);
      toast.error(apiMessage(error, t("deploy.failed")));
    }
  }, [application.id, application.last_deployed_at, refresh, stopPoll, t]);

  return (
    <div className="space-y-6">
      <DeployCard
        application={application}
        deploying={deploying}
        canManage={canManage}
        onDeploy={deploy}
      />
      <WebhookCard
        application={application}
        providers={providers}
        canManage={canManage}
        onChange={setApplication}
      />
    </div>
  );
}
