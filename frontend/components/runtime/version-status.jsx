import { Loader2, TriangleAlert } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { EmptyState } from "@/components/data-table/empty-state";

// `ready`, or a missing `status` (older responses), renders nothing.

export function versionState(version) {
  // A failed REMOVAL leaves the version installed and working, so it is not
  // treated as a failed install.
  if (removeFailed(version)) return null;
  return version?.status && version.status !== "ready" ? version.status : null;
}

export function removeFailed(version) {
  return version?.status === "failed" && version?.reason === "remove_failed";
}

// `useTranslations`, not `getTranslations`: renders in both a server and a client component.
export function RuntimeStatusBadge({ version, namespace }) {
  const t = useTranslations(namespace);
  const state = versionState(version);
  if (removeFailed(version)) {
    return (
      <Badge variant="destructive" className="font-normal" title={version.message ?? undefined}>
        {t("versions.statusRemoveFailed")}
      </Badge>
    );
  }
  if (!state) return null;

  if (state === "failed") {
    return (
      <Badge variant="destructive" className="font-normal">
        {t("versions.statusFailed")}
      </Badge>
    );
  }

  return (
    <Badge variant="warning" className="font-normal">
      <Loader2 className="size-3 animate-spin" />
      {state === "removing"
        ? t("versions.statusRemoving")
        : version.queued
          ? t("versions.statusQueued")
        : // The apt phase, not a percentage: the total is unknown until apt finishes.
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
          // The server's explanation when it has one; the reference gets its
          // own line because it is meant to be copied.
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
  // No worker has picked it up yet: "installing since 3 minutes ago" would read as stuck.
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
          : // "Started 17 minutes ago" shows whether it is progressing or stuck.
            // Removing has its own sentence (the install one promises extensions).
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
