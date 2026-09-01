"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { deployApplication } from "@/lib/api/applications";
import { readApplication } from "@/lib/api/deployment";
import { applicationSchema } from "@/lib/schemas/application";
import { apiMessage } from "@/lib/api/error-message";
import { provisionStepLabel } from "@/lib/applications/provision-steps";
import { DeployCard } from "@/components/applications/deployment/deploy-card";
import { WebhookCard } from "@/components/applications/deployment/webhook-card";
import { DeploySettingsCard } from "@/components/applications/deployment/deploy-settings-card";
import { RuntimeCard } from "@/components/applications/deployment/runtime-card";
import { DeployHistoryCard } from "@/components/applications/deployment/deploy-history-card";

// A deploy flips status to "provisioning" while it runs; poll the resource so
// steps[] and the commit/timestamp update in place without leaving the page.
const POLL_MS = 2500;

export function DeploymentPanel({
  application: initial,
  providers,
  canManage,
  deployments = [],
  settings = null,
}) {
  const t = useTranslations("applications.deployment");
  // Step labels live under `details` — the namespace of the first screen that
  // needed them — and a raw `verify` in a toast is as unreadable as in a card.
  const ts = useTranslations("applications.details");
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
            toast.error(
              t("deploy.failedAt", { step: provisionStepLabel(next.failed_step, ts) }),
            );
          } else if (next.last_deployed_at !== before) {
            toast.success(t("deploy.done"));
          }
        }
      }, POLL_MS);
    } catch (error) {
      setDeploying(false);
      toast.error(apiMessage(error, t("deploy.failed")));
    }
  }, [application.id, application.last_deployed_at, refresh, stopPoll, t, ts]);

  return (
    <div className="space-y-6">
      <DeployCard
        application={application}
        deploying={deploying}
        canManage={canManage}
        onDeploy={deploy}
      />
      {/* What a deploy runs, before the record of what it ran. */}
      {settings ? (
        <DeploySettingsCard
          applicationId={application.id}
          settings={settings}
          canManage={canManage}
        />
      ) : null}

      {/* Only a site that runs a process has one to start, and the fields are
          meaningless on a static or PHP site — the API nulls them there. */}
      {application.has_process ? (
        <RuntimeCard application={application} canManage={canManage} />
      ) : null}

      <WebhookCard
        application={application}
        providers={providers}
        canManage={canManage}
        onChange={setApplication}
      />

      <DeployHistoryCard
        applicationId={application.id}
        deployments={deployments}
        canManage={canManage}
      />
    </div>
  );
}
