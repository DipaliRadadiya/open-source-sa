import { Loader2, TriangleAlert } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import { EmptyState } from "@/components/data-table/empty-state";
import { HoverHint } from "@/components/ui/hover-hint";

export function versionState(version) {
  if (removeFailed(version)) return null;
  return version?.status && version.status !== "ready" ? version.status : null;
}

export function removeFailed(version) {
  return version?.status === "failed" && version?.reason === "remove_failed";
}

export function RuntimeStatusBadge({ version, namespace, className }) {
  const t = useTranslations(namespace);
  const state = versionState(version);
  if (removeFailed(version)) {
    return (
      <HoverHint content={version.message ?? undefined}><Badge variant="destructive" className={cn("font-normal", className)}>
        {t("versions.statusRemoveFailed")}
      </Badge></HoverHint>
    );
  }
  if (!state) return null;

  if (state === "failed") {
    return (
      <Badge variant="destructive" className={cn("font-normal", className)}>
        {t("versions.statusFailed")}
      </Badge>
    );
  }

  return (
    <Badge variant="warning" className={cn("font-normal", className)}>
      <Loader2 className="size-3 animate-spin" />
      {state === "removing"
        ? t("versions.statusRemoving")
        : version.queued
          ? t("versions.statusQueued")
        :
          version.current_step
          ? t(`versions.steps.${version.current_step}`)
          : t("versions.statusInstalling")}
    </Badge>
  );
}

export function RuntimeStatusNotice({ version, versionLabel, namespace }) {
  const t = useTranslations(namespace);
  const state = versionState(version);
  if (!state) return null;

  if (state === "failed") {
    return (
      <EmptyState
        icon={TriangleAlert}
        title={t("versions.installFailedTitle", { version: versionLabel })}
        description={
          <>
            {version.message || t("versions.installFailedBody")}
            {version.reference ? (
              <span className="mt-1.5 block font-mono text-xs break-all">{version.reference}</span>
            ) : null}
          </>
        }
      />
    );
  }

  const removing = state === "removing";
  const queued = !removing && version.queued;

  return (
    <EmptyState
      icon={Loader2}
      title={
        removing
          ? t("versions.removingTitle", { version: versionLabel })
          : queued
            ? t("versions.queuedTitle", { version: versionLabel })
            : t("versions.installingTitle", { version: versionLabel })
      }
      description={
        queued
          ? t("versions.queuedBody")
          :
            version.started_at_human
            ? t(removing ? "versions.removingSince" : "versions.installingSince", {
                when: version.started_at_human,
              })
            : removing
              ? t("versions.removingBody")
              : t("versions.installingBody")
      }
    />
  );
}
