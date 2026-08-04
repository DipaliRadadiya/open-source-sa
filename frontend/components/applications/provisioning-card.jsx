"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Check, CircleAlert, Loader2, RotateCw } from "lucide-react";
import { toast } from "sonner";
import { retryProvisioning } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

const STATUS_VARIANT = { active: "success", failed: "destructive", provisioning: "warning", pending: "secondary" };

export function ProvisioningCard({ application, canManage = false }) {
  const t = useTranslations("applications.details");
  const router = useRouter();
  const [retrying, setRetrying] = useState(false);
  const working = application.status === "pending" || application.status === "provisioning";

  useEffect(() => {
    if (!working) return undefined;
    const timer = window.setInterval(() => router.refresh(), 4000);
    return () => window.clearInterval(timer);
  }, [router, working]);

  async function retry() {
    setRetrying(true);
    try {
      await retryProvisioning(application.id);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("failed", { step: application.failed_step ?? "—" })));
    } finally {
      setRetrying(false);
    }
  }

  const description = application.status === "active"
    ? t("active")
    : application.status === "failed"
      ? t("failed", { step: application.failed_step ?? "—" })
      : application.status === "provisioning"
        ? t("inProgress")
        : t("pending");

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between gap-4 space-y-0">
        <div className="space-y-1"><CardTitle>{t("provisioning")}</CardTitle><CardDescription className="flex items-center gap-2">{working ? <Loader2 className="size-3.5 shrink-0 animate-spin" /> : null}{description}</CardDescription></div>
        <Badge variant={STATUS_VARIANT[application.status] ?? "secondary"} className="shrink-0 font-normal">{application.status_title ?? application.status}</Badge>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* The spinner rides with the description above. Repeating it here said
            "Waiting to start" and "In progress" at once on a pending site. */}
        <div className="space-y-2">
          <p className="text-sm font-medium">{t("steps")}</p>
          {application.steps?.length ? <ol className="space-y-2">{application.steps.map((step) => <li key={step} className="flex items-center gap-2 text-sm"><Check className="size-4 text-emerald-600 dark:text-emerald-400" /><span>{step}</span></li>)}</ol> : <p className="text-sm text-muted-foreground">{t("noSteps")}</p>}
        </div>
        {application.status === "failed" && application.reference ? <p className="flex items-center gap-2 rounded-md border border-destructive/20 bg-destructive/5 px-3 py-2 text-sm text-destructive"><CircleAlert className="size-4 shrink-0" />{t("reference", { reference: application.reference })}</p> : null}
        {application.status === "failed" && canManage ? <Button onClick={retry} disabled={retrying}>{retrying ? <Loader2 className="size-4 animate-spin" /> : <RotateCw className="size-4" />}{retrying ? t("retrying") : t("retry")}</Button> : null}
      </CardContent>
    </Card>
  );
}
