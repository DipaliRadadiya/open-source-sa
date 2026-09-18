"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowRight, Check, HardDrive, Tags } from "lucide-react";
import { cn } from "@/lib/utils";
import { changeApplicationSiteType } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";

/**
 * One side of the change: a mark and the name it belongs to.
 *
 * `min-w-0` + `truncate` because a long title ("phpMyAdmin", "From Git repo")
 * must shorten rather than push the arrow off-centre.
 */
function TypeSide({ name, title, emphasis = false }) {
  return (
    <span className="flex min-w-0 items-center gap-2">
      <SiteTypeLogo name={name} size="h-6 w-8" />
      <span
        className={cn(
          "truncate text-sm",
          emphasis ? "font-medium text-foreground" : "text-muted-foreground",
        )}
        title={title}
      >
        {title}
      </span>
    </span>
  );
}

/**
 * One answer, labelled with the question it answers.
 *
 * A `<dl>` row rather than a bullet: "Changes" / "Unchanged" are the terms and
 * the sentences are their definitions, which is what a description list is
 * for — and it gives a screen reader the pairing for free.
 */
function Consequence({ icon: Icon, tone, label, children }) {
  return (
    <div className="flex items-start gap-2.5 p-3">
      <Icon
        className={cn(
          "mt-0.5 size-4 shrink-0",
          tone === "success" ? "text-success" : "text-warning",
        )}
        aria-hidden
      />
      <div className="min-w-0 space-y-0.5">
        <dt className="text-xs font-medium text-foreground">{label}</dt>
        <dd className="text-xs leading-snug text-muted-foreground">{children}</dd>
      </div>
    </div>
  );
}

/**
 * Confirm a relabel.
 *
 * One dialog for both directions — accepting a suggestion and narrowing back
 * to a generic type — because the question is identical and only the sentence
 * above it changes. `target` decides which.
 *
 * The body answers two questions and nothing else. From UpdateSiteTypeRequest's
 * own docblock: "**This changes what the panel offers, not what is on disk.**
 * Nothing is installed, nothing is downloaded, no installer runs. … Said
 * plainly here and in API_REFERENCE.md because the opposite assumption — that
 * changing the type converts the site — is the natural one." Somebody who
 * reads "change to WordPress" and expects an install has been misled by us,
 * not by themselves, so the correction goes in front of the button.
 *
 * But the correction alone is not enough, which is what the first version got
 * wrong: it said only what would NOT happen, leaving no stated reason to press
 * the button at all. Hence the pair — what changes, and what does not.
 */
export function SiteTypeRelabelDialog({
  open,
  onOpenChange,
  application,
  target,
  targetTitle,
  // The file the verdict rests on, when this is an accepted suggestion. Shown
  // instead of the confidence score: `wp-config.php` is checkable, "95" is a
  // number nobody can act on or argue with.
  matched = null,
}) {
  const t = useTranslations("applications.siteTypeDetection");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  function handleOpenChange(next) {
    // Cleared HERE rather than on open: this dialog is opened by a button that
    // sets `target` directly, which skips onOpenChange entirely, so a stale
    // refusal from the last attempt would survive into the next one.
    if (!next) setError(null);
    onOpenChange(next);
  }

  async function confirm() {
    setPending(true);
    setError(null);
    try {
      await changeApplicationSiteType(application.id, target);
      toast.success(t("applied", { type: targetTitle }));
      onOpenChange(false);
      /*
       * `router.refresh()`, not a local patch. The type decides which screens
       * this site has — WordPress adds Staging, Clone and Magic Login — so the
       * sidebar and the whole nav for this application change. Patching one
       * field would leave the rail claiming the old set.
       */
      router.refresh();
    } catch (err) {
      // The backend's refusals are sentences worth reading: the git one
      // explains that relabelling would hide the Deployments and Workers
      // screens without stopping the workers or the deploy webhook. Kept
      // verbatim, in the dialog, instead of a toast that clears in 4s.
      setError(apiMessage(err, t("failed")));
    } finally {
      setPending(false);
    }
  }

  if (!target) return null;

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={Tags}
      title={t("confirmTitle", { type: targetTitle })}
      description={matched ? t("confirmFound", { file: matched }) : t("confirmNarrow")}
      confirmLabel={t("confirmAction")}
      pending={pending}
      error={error}
      onConfirm={confirm}
    >
      <div className="space-y-3">
        {/* Each mark paired with its OWN name, and ONE arrow between the pairs.
            The first version put both logos together and then repeated the
            change as text — "logo → logo, then name → name", two arrows for
            one change, and it was reported as confusing. The pairing also
            fixes which logo is which: most of these marks do not spell their
            own name, so a bare php glyph beside a bare WordPress glyph asked
            the reader to decode both before reading the sentence that named
            them anyway. */}
        <div className="flex items-center justify-center gap-3 rounded-lg border bg-muted/30 p-3">
          <TypeSide
            name={application.site_type}
            title={application.site_type_title ?? application.site_type}
          />
          <ArrowRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />
          {/* The destination, and the only one of the two that is the answer to
              the question in the title — so it keeps foreground weight while
              the current type stays muted. */}
          <TypeSide name={target} title={targetTitle} emphasis />
        </div>

        {/* Two labelled rows, because everybody arrives with exactly two
            questions — "what will this do?" and "will it touch my site?" — and
            an unlabelled amber warning answered only the second one, in the
            voice of a hazard rather than an answer.

            The first version said only what does NOT happen. That is the
            important half, but on its own it leaves the button looking
            pointless: correcting a misconception without ever stating the
            benefit. What changes is that the panel starts offering the screens
            it keeps for that application, which is the entire reason to press
            this. */}
        <dl className="divide-y rounded-lg border">
          <Consequence icon={Check} tone="success" label={t("changesLabel")}>
            {t("changesBody", { type: targetTitle })}
          </Consequence>
          <Consequence icon={HardDrive} tone="warning" label={t("unchangedLabel")}>
            {t("unchangedBody")}
          </Consequence>
        </dl>

        {/* The question nobody asks out loud before an irreversible-looking
            change. Narrowing back to a generic type is ALWAYS allowed and
            needs no evidence — the endpoint returns early for it — so this is
            a promise the API actually keeps, not reassurance. Only shown for
            the widening direction; on a narrowing there is nothing to undo
            that this sentence would describe correctly. */}
        {matched ? (
          <p className="text-xs text-muted-foreground">
            {t("reversible", { from: application.site_type_title ?? application.site_type })}
          </p>
        ) : null}
      </div>
    </ConfirmDialog>
  );
}
