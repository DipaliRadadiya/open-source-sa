import { Loader2, TriangleAlert } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { EmptyState } from "@/components/data-table/empty-state";

/**
 * What a runtime version is doing, said the same way on both pages.
 *
 * PHP grew all four states (`installing | removing | ready | failed`) and Node
 * grew none — a Node version being installed rendered as an ordinary ready one,
 * so there was nothing to look at at all. Rather than copying PHP's markup into
 * Node and letting the two drift, both now read from here.
 *
 * `ready` (and a missing `status`, which older responses omit) render nothing:
 * a settled version is described by the rest of the card.
 */

export function versionState(version) {
  return version?.status && version.status !== "ready" ? version.status : null;
}

/**
 * The badge beside the version name.
 *
 * `useTranslations` rather than `getTranslations`: this renders inside the PHP
 * page (a server component) and inside the Node version card (a client one),
 * and only the hook form works in both.
 */
export function RuntimeStatusBadge({ version, namespace }) {
  const t = useTranslations(namespace);
  const state = versionState(version);
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
        : // The phase apt reported, not a percentage. There is no honest
          // percentage available — the install is one apt call whose total is
          // unknown until it finishes — so a number would be invented. A named
          // phase is something the server actually said.
          version.current_step
          ? t(`versions.steps.${version.current_step}`)
          : t("versions.statusInstalling")}
    </Badge>
  );
}

/**
 * The block that stands in for whatever cannot be shown while the version is
 * not on disk. Rendering nothing there read as a broken page.
 *
 * Removing used to fall through to "Install failed" here, because the only
 * branch was `installing ? … : failed` — so a purge in progress accused itself
 * of a failure that had not happened.
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
          // The server's own explanation, when it has one. Ours would be a
          // guess about a failure we did not witness. The reference gets its
          // own line — it is a string to copy, not prose.
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
        // "Started 17 minutes ago" is what tells you whether it is progressing
        // or wedged; "this takes a few minutes" never does.
        //
        // Removing gets its own sentence rather than reusing the install one,
        // which promises "extensions will appear once it finishes" — the
        // opposite of what happens when a version is being purged.
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
