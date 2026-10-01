import { Loader2, TriangleAlert } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { EmptyState } from "@/components/data-table/empty-state";

/**
 * What a runtime version is doing, shared by the PHP and Node pages.
 *
 * `ready` (and a missing `status`, which older responses omit) render nothing:
 * a settled version is described by the rest of the card.
 */

export function versionState(version) {
  // A failed REMOVAL leaves the version installed and working, so it is not
  // treated as a failed install.
  if (removeFailed(version)) return null;
  return version?.status && version.status !== "ready" ? version.status : null;
}

export function removeFailed(version) {
  return version?.status === "failed" && version?.reason === "remove_failed";
}

/**
 * The badge beside the version name. `useTranslations`, not `getTranslations`:
 * it renders in both a server component (PHP page) and a client one (Node card).
 */
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
        : // The apt phase, not a percentage: the total is unknown until apt finishes.
          version.current_step
          ? t(`versions.steps.${version.current_step}`)
          : t("versions.statusInstalling")}
    </Badge>
  );
}

/**
 * Stands in for whatever cannot be shown while the version is not on disk,
 * with distinct wording for installing, removing and failed.
 */
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

  return (
    <EmptyState
      icon={Loader2}
      title={
        removing
          ? t("versions.removingTitle", { version: versionLabel })
          : t("versions.installingTitle", { version: versionLabel })
      }
      description={
        // "Started 17 minutes ago" shows whether it is progressing or stuck.
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
