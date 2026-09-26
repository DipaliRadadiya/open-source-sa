"use client";

import { useState } from "react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { ArrowUpFromLine, ExternalLink, FlaskConical, Trash2, TriangleAlert } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { CreateStagingDialog } from "@/components/applications/staging/create-staging-dialog";
import { PushStagingDialog } from "@/components/applications/staging/push-staging-dialog";
import { DeleteApplicationDialog } from "@/components/applications/delete-application-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

/**
 * One site's staging copy.
 *
 * Two states and one dangerous action. The staging site is an ordinary
 * application, so it links to its own pages rather than being managed here —
 * this screen exists for the two things that only make sense as a pair:
 * making the copy, and pushing it back over production.
 *
 * Push is the reason the screen is careful. It takes production offline and
 * rsyncs with `--delete`; `files` mode keeps no safety copy at all. So it is
 * a two-step, typed-confirmation action rather than a button, and the mode
 * picker states what each choice destroys instead of offering a default.
 */
export function StagingPanel({ appId, production, staging, canManage, canDelete = false }) {
  const t = useTranslations("applications.staging");
  const tApp = useTranslations("applications");
  const [creating, setCreating] = useState(false);
  const [pushing, setPushing] = useState(false);
  const [removing, setRemoving] = useState(false);
  const ready = staging?.status === "active";

  // This site IS the copy. Offering to stage it would make a staging site of
  // a staging site — the API would allow it, and nothing about it is useful.
  // What the reader actually wants from here is the way back to the original.
  if (production?.is_staging) {
    return (
      <div className="max-w-4xl">
        <Card className="gap-0 overflow-hidden py-0 shadow-sm">
          <CardContent className="flex flex-wrap items-center gap-3 px-5 py-4">
            <span className="flex shrink-0 items-center justify-center text-muted-foreground">
              <FlaskConical className="size-4.5 text-primary" />
            </span>
            <div className="min-w-60 flex-1 space-y-1">
              <p className="font-semibold">{t("isCopy.title")}</p>
              <p className="text-sm text-muted-foreground">{t("isCopy.body")}</p>
            </div>
            {production.production_application_id ? (
              <Button asChild variant="outline">
                <Link href={`/applications/${production.production_application_id}`}>
                  {t("isCopy.action")}
                </Link>
              </Button>
            ) : null}
          </CardContent>
        </Card>
      </div>
    );
  }

  if (!staging) {
    return (
      <div className="max-w-4xl">
        <Card className="gap-0 overflow-hidden py-0 shadow-sm">
          <CardContent className="flex flex-col items-center gap-3 px-6 py-10 text-center">
            <span className="flex size-11 items-center justify-center rounded-lg bg-muted">
              <FlaskConical className="size-5 text-muted-foreground" />
            </span>
            <div className="space-y-1.5">
              <p className="font-medium">{t("empty.title")}</p>
              <p className="mx-auto max-w-md text-sm text-muted-foreground">{t("empty.body")}</p>
            </div>
            {canManage ? (
              <Button className="mt-1" onClick={() => setCreating(true)}>
                {t("empty.action")}
              </Button>
            ) : (
              <p className="mx-auto max-w-md text-xs text-muted-foreground">{t("noPermission")}</p>
            )}
          </CardContent>
        </Card>

        {/* Remounted on each open so a domain typed once is not still in the
            field the next time — the dialog never sees `onOpenChange` on the
            way in to clear it for itself. */}
        <CreateStagingDialog
          key={String(creating)}
          appId={appId}
          production={production}
          open={creating}
          onOpenChange={setCreating}
        />
      </div>
    );
  }

  return (
    <div className="max-w-4xl space-y-4">
      <Card className="gap-0 overflow-hidden py-0 shadow-sm">
        <CardContent className="flex flex-wrap items-center gap-3 px-5 py-4">
          <span className="flex shrink-0 items-center justify-center text-muted-foreground">
            <FlaskConical className="size-4.5 text-primary" />
          </span>
          <div className="min-w-60 flex-1 space-y-1">
            <p className="flex flex-wrap items-center gap-2 font-semibold">
              {staging.name}
              <Badge variant={staging.status === "active" ? "success" : "muted"}>
                {staging.status_title ?? staging.status}
              </Badge>
            </p>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
              <a
                // See application-row-actions: link to the URL the API
                // reports, which is http:// until a certificate is servable.
                href={staging.url ?? `https://${staging.domain}`}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1.5 underline-offset-4 hover:text-foreground hover:underline"
              >
                {staging.domain}
                <ExternalLink className="size-3.5" />
              </a>
              {/* How old the copy is, because that is the question before a
                  push: a three-week-old copy pushed over production takes
                  three weeks of production with it. No "·" before it: on a
                  phone the age wraps and the dot was left hanging at the end
                  of the domain line. */}
              {staging.created_at_human ? (
                <span>{t("copyAge", { age: staging.created_at_human })}</span>
              ) : null}
            </div>
          </div>
          {/* The copy is a site like any other — everything except the push
              is done on its own pages, so this points there rather than
              growing a second, smaller version of them here. */}
          <Button asChild variant="outline">
            <Link href={`/applications/${staging.id}`}>{t("manageAction")}</Link>
          </Button>
        </CardContent>

        <div className="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/20 px-5 py-4">
          <div className="min-w-0 space-y-1">
            <p className="text-sm font-medium">{t("push.title")}</p>
            <p className="text-sm text-muted-foreground">
              {t("push.body", { domain: production.domain })}
            </p>
            {/* A copy that never finished (the backend can leave one behind
                when creating fails) has nothing to push — offering the most
                destructive action in the panel on it was the wrong default. */}
            {!ready ? (
              <p className="flex items-start gap-2 pt-1 text-sm text-warning">
                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                <span>{t("push.notReady")}</span>
              </p>
            ) : !canManage ? (
              <p className="pt-1 text-xs text-muted-foreground">{t("noPermission")}</p>
            ) : null}
          </div>
          {canManage && ready ? (
            <Button variant="destructive" onClick={() => setPushing(true)}>
              <ArrowUpFromLine className="size-4" />
              {t("push.action")}
            </Button>
          ) : null}
        </div>
      </Card>

      {/* Its own card, away from Push: both are red, and the two must never
          be one misclick apart. */}
      <Card className="gap-0 overflow-hidden py-0 shadow-sm">
        <CardContent className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
          <div className="min-w-60 flex-1 space-y-1">
            <p className="text-sm font-medium">{t("remove.title")}</p>
            <p className="text-sm break-words text-muted-foreground">
              {t("remove.body", { domain: staging.domain, production: production.domain })}
            </p>
          </div>
          <ReasonTooltip reason={canDelete ? null : tApp("noPermission")}>
            <Button variant="destructive" className="shrink-0" disabled={!canDelete} onClick={() => setRemoving(true)}>
              <Trash2 className="size-4" />
              {t("remove.action")}
            </Button>
          </ReasonTooltip>
        </CardContent>
      </Card>

      <DeleteApplicationDialog application={staging} open={removing} onOpenChange={setRemoving} />

      {/* Same contract, and it matters more here: the typed domain is the
          safeguard, so it must never be pre-filled from a previous visit. */}
      <PushStagingDialog
        key={String(pushing)}
        appId={appId}
        production={production}
        staging={staging}
        open={pushing}
        onOpenChange={setPushing}
      />
    </div>
  );
}
