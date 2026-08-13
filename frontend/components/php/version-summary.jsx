"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Trash2, Loader2 } from "lucide-react";
import { LifecycleBadge } from "@/components/runtime/lifecycle-badge";
import { setDefaultPhpVersion, removePhpVersion } from "@/lib/api/php";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Card,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { apiMessage } from "@/lib/api/error-message";

/**
 * What the selected version is, and everything you can do from here.
 *
 * One card rather than a card per action: the facts you need to decide
 * ("is anything using this?") and the buttons that act on them belong in the
 * same glance. `makeDefault` and `remove` appear only when they are genuinely
 * available — on a one-version server neither exists, because you cannot remove
 * the only version and it is already the default.
 *
 * `children` carries the php.ini button, owned by the page (a Server
 * Component). Install lives beside the version picker, not here.
 */
export function VersionSummary({
  version,
  canManage,
  lifecycleAvailable = false,
  children,
}) {
  const t = useTranslations("php");
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);

  const usedBy = version.in_use_by ?? 0;
  const sites = version.sites ?? [];

  // The API omits `status` on older responses; absent means ready.
  const installState = version.status && version.status !== "ready" ? version.status : null;

  // Nothing is on disk, so anything that reads or writes this install fails.
  // Removing is the exception — clearing up a failed install is exactly what
  // you would want to do next.
  const notReadyReason = installState
    ? installState === "installing"
      ? t("versions.stillInstalling")
      : // Removing is in-flight like installing, so everything that reads or
        // writes this version has to be refused while it runs — including
        // Remove itself, which used to be pressable a second time and
        // answered 404 once apt had finished.
        installState === "removing"
        ? t("versions.stillRemoving")
        : t("versions.installFailedShort")
    : null;

  const removeReason = !canManage
    ? t("noPermission")
    : // Already going. Pressing Remove again used to send a second DELETE,
      // which answered 404 the moment apt had finished the first — the error
      // that made this look broken when it had actually worked.
      installState === "removing"
      ? t("versions.stillRemoving")
      : version.in_use_by_panel
        ? t("versions.panelRuns")
        : version.is_default
          ? t("versions.isDefault")
          : usedBy > 0
            ? t("versions.usedBy", { count: usedBy })
            : null;

  async function makeDefault() {
    setPending(true);
    try {
      await setDefaultPhpVersion(version.version);
      toast.success(t("versions.defaultSet", { version: version.version }));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("versions.defaultFailed")));
    } finally {
      setPending(false);
    }
  }

  async function remove() {
    setPending(true);
    try {
      await removePhpVersion(version.version);
      // "Removing", not "removed": this is a 202 now, and apt has minutes of
      // work ahead of it. Saying it was done was the reason a version still
      // sitting there looked like a bug rather than a purge in progress.
      toast.success(t("versions.removing", { version: version.version }));
      setConfirming(false);
      router.refresh();
    } catch (error) {
      // The API names the sites in its message, which is more useful than
      // anything this page could compose.
      toast.error(apiMessage(error, t("versions.removeFailed")));
    } finally {
      setPending(false);
    }
  }

  // Each action appears only when it genuinely applies: the default version
  // cannot be made default again, and the panel`s own version cannot go.
  const showMakeDefault = !version.is_default;
  const showRemove = !version.in_use_by_panel;

  return (
    <Card>
      <CardHeader>
        {/* php.ini sits with the version it edits, on the title line. It was in
            the footer next to Install, where the two most-used controls on the
            page were a pair of unrelated actions sharing a bar. */}
        <div className="flex flex-wrap items-start justify-between gap-3">
        <CardTitle className="flex flex-wrap items-center gap-2 text-base font-semibold">
          {t("versions.name", { version: version.version })}
          {/* Filled, not outlined: "Default" is a state this version is in, and
              it should not read like the outline tags used for plain labels. */}
          {version.is_default ? (
            <Badge variant="secondary" className="font-normal">
              {t("versions.default")}
            </Badge>
          ) : null}
          {/* Said out loud. A version whose install failed used to look exactly
              like a healthy one — same title, same "no sites use this yet". */}
          {installState === "failed" ? (
            <Badge variant="destructive" className="font-normal">
              {t("versions.statusFailed")}
            </Badge>
          ) : installState === "removing" ? (
            // Its own state rather than hiding the card the moment the button
            // is pressed: the purge takes minutes, and a card that vanished
            // immediately would leave nothing to look at while it happened —
            // and nothing to show if it failed.
            <Badge variant="warning" className="font-normal">
              <Loader2 className="size-3 animate-spin" />
              {t("versions.statusRemoving")}
            </Badge>
          ) : installState === "installing" ? (
            <Badge variant="warning" className="font-normal">
              <Loader2 className="size-3 animate-spin" />
              {/* The phase apt reported, not a percentage. There is no honest
                  percentage available: the install is one apt call and its
                  total is unknown until it finishes, so a number would be
                  invented. A named phase is something the server actually
                  said. */}
              {version.current_step
                ? t(`versions.steps.${version.current_step}`)
                : t("versions.statusInstalling")}
            </Badge>
          ) : (
            <LifecycleBadge
              lifecycle={version.lifecycle}
              namespace="php"
              available={lifecycleAvailable}
            />
          )}
        </CardTitle>

          {/* Every action for this version on one line, with the version it
              acts on. A footer bar underneath repeated the card's own subject
              and split the controls across two places for no reason. */}
          {/* Not shrink-0. A shrink-0 flex item takes its max-content width —
              all three buttons on one line — and refuses to give any of it
              back, so its own flex-wrap never gets the chance to wrap and the
              row runs past the card instead. Longer locales hit this first:
              "Hacer predeterminada" is half again the width of "Make
              default". */}
          <div className="flex min-w-0 flex-wrap items-center gap-2">
            {children}

            {!showMakeDefault ? null : (
              <ReasonTooltip reason={notReadyReason ?? (canManage ? null : t("noPermission"))}>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={!canManage || pending || Boolean(notReadyReason)}
                  onClick={makeDefault}
                >
                  {t("versions.makeDefault")}
                </Button>
              </ReasonTooltip>
            )}

            {/* Hidden entirely on the panel's own version — the API refuses it,
                but a button that exists to be refused is still a trap. */}
            {!showRemove ? null : (
              <ReasonTooltip reason={removeReason}>
                <Button
                  variant="ghost"
                  size="sm"
                  className="text-destructive/80 hover:bg-destructive/10 hover:text-destructive"
                  disabled={Boolean(removeReason) || pending}
                  onClick={() => setConfirming(true)}
                >
                  {t("versions.remove")}
                </Button>
              </ReasonTooltip>
            )}
          </div>
        </div>

        {/* The count answers "can I remove this?"; the names answer "what
            breaks if I do?". Run together as one sentence, ten site names —
            several of them near-identical, like "Blog" and "Blog (Staging)" —
            became a grey paragraph nobody reads, and the count was buried at
            the end of it. */}
        <CardDescription>
          {usedBy > 0 ? t("versions.usedByCount", { count: usedBy }) : t("versions.usedByNone")}
          {/* Only when the date is news. On a supported version the green badge
              already says what you need, and a 2028 date is trivia. */}
          {lifecycleAvailable &&
          version.lifecycle?.eol_date &&
          version.lifecycle.status !== "active"
            ? ` ${
                version.lifecycle.status === "eol"
                  ? t("lifecycle.endedOn", { date: version.lifecycle.eol_date })
                  : t("lifecycle.endsOn", { date: version.lifecycle.eol_date })
              }`
            : null}
        </CardDescription>

        {/* apt's own output, while it is installing and after it has failed.
            The badge says which phase it reached; only this says why it
            stopped there — "unable to locate package" and "could not get
            lock" are the same failed install without it, and both have
            different answers.

            Kept after the failure too, deliberately: the moment someone wants
            to read the output is the moment it went wrong. */}
        {installState && version.output ? (
          <pre
            className="mt-2 max-h-40 overflow-auto rounded-md bg-muted p-2 font-mono text-[11px] leading-relaxed text-muted-foreground"
            // Announced politely: this updates every poll while an install
            // runs, and an assertive region would interrupt a screen reader
            // several times a minute for output nobody asked to hear.
            aria-live="polite"
          >
            {version.output.trimEnd()}
          </pre>
        ) : null}

        {/* Tags, not prose: each name is one scannable unit, so the near-
            duplicates stop reading as one long string. The API sends at most
            five and tells us how many it held back. */}
        {sites.length > 0 ? (
          <ul className="flex flex-wrap gap-1.5 pt-1">
            {sites.map((site) => (
              <li
                key={site}
                className="rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground"
              >
                {site}
              </li>
            ))}
            {version.sites_truncated && usedBy > sites.length ? (
              <li className="px-1 py-0.5 text-xs text-muted-foreground">
                {t("versions.moreCount", { count: usedBy - sites.length })}
              </li>
            ) : null}
          </ul>
        ) : null}

        {/* "Default" reads as "every site now uses this", which it isn't. Said
            plainly and only where the badge is, rather than left to be found
            out by changing it. */}
        {version.is_default ? (
          <p className="text-xs text-muted-foreground">{t("versions.defaultHint")}</p>
        ) : null}
      </CardHeader>

      <ConfirmDialog
        open={confirming}
        onOpenChange={(open) => !pending && setConfirming(open)}
        icon={Trash2}
        tone="destructive"
        title={t("versions.confirmRemoveTitle", { version: version.version })}
        description={t("versions.confirmRemoveBody")}
        cancelLabel={t("versions.confirmCancel")}
        confirmLabel={t("versions.remove")}
        pending={pending}
        onConfirm={remove}
      />
    </Card>
  );
}
