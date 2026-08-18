"use client";

import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import {
  CheckCircle2,
  CircleHelp,
  HardDrive,
  KeyRound,
  Loader2,
  MoreHorizontal,
  Pencil,
  PlugZap,
  Trash2,
  TriangleAlert,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

/**
 * One storage destination.
 *
 * The name leads, because that is what someone picks from the dropdown on a
 * backup target later. Underneath it is the thing that actually identifies
 * where the data goes — bucket, and the prefix inside it — since two
 * destinations can easily differ only by prefix.
 *
 * Two layouts, one set of content. On a wide screen it reads left to right:
 * identity, then region and age, then the actions. On a phone everything
 * stacks into ONE indent under the icon and the actions drop to their own
 * line — the first attempt let the actions share the top line and put the
 * facts flush against the card edge, which gave the row four different left
 * edges and read as clutter.
 *
 * The test verdict SURVIVES a reload. It used to be deliberately ephemeral,
 * because the API stored nothing and a badge outliving the request would have
 * been claiming knowledge the panel did not have. The API now persists it —
 * and clears it whenever a credential, endpoint, region or bucket changes — so
 * showing it is honest, and losing it on every reload was just forgetting.
 *
 * The age is shown with it on purpose: "tested 40 days ago" is not "works
 * tonight", and a tick with no date invites exactly that reading.
 */
export function DestinationRow({
  destination,
  canManage,
  testing,
  result,
  onTest,
  onEdit,
  onReplace,
  onDelete,
}) {
  const t = useTranslations("storage");
  const location = [destination.bucket, destination.prefix].filter(Boolean).join("/");

  // Written once, placed twice — inside the content column on a phone, in its
  // own column on a wide screen. Two copies of this markup is how they drift.
  const facts = (
    <>
      <p className="text-foreground">{destination.region || t("row.regionDefault")}</p>
      {destination.created_at_human ? (
        <p>{t("row.added", { when: destination.created_at_human })}</p>
      ) : null}
    </>
  );

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <div className="flex flex-wrap items-start gap-3 py-3.5">
        <div className="flex w-full min-w-0 gap-3 sm:w-auto sm:flex-1">
          <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
            <HardDrive className="size-4" />
          </span>
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <span className="min-w-0 font-medium break-all">{destination.name}</span>
              {/* Not "verified" — only that both secret columns are populated.
                  Whether they WORK is what Test answers. */}
              {destination.has_credentials ? (
                <Badge variant="secondary">{t("row.credentialsSet")}</Badge>
              ) : (
                <>
                  <Badge variant="warning">{t("row.credentialsMissing")}</Badge>
                  {/* Without keys this destination cannot work at all, so the fix
                      is offered next to the problem rather than hidden behind the
                      overflow menu. */}
                  {canManage ? (
                    <Button
                      type="button"
                      variant="link"
                      size="sm"
                      className="h-auto p-0 text-xs"
                      onClick={onReplace}
                    >
                      {t("row.addCredentials")}
                    </Button>
                  ) : null}
                </>
              )}
            </div>
  
            <p className="truncate font-mono text-xs text-muted-foreground">{location}</p>
            {destination.endpoint ? (
              <p className="truncate font-mono text-xs text-muted-foreground">
                {destination.endpoint}
              </p>
            ) : (
              <p className="text-xs text-muted-foreground">{t("row.awsDefault")}</p>
            )}
  
            {/* Phone: region and age join the same indent as everything else
                rather than starting a new left edge at the card border. */}
            <div className="space-y-0.5 text-xs text-muted-foreground sm:hidden">{facts}</div>
  
            {testing ? (
              <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Loader2 className="size-3 animate-spin" />
                {t("row.testing")}
              </p>
            ) : result ? (
              <div className="space-y-1.5 pt-0.5">
                <p
                  className={
                    result.ok
                      ? "flex items-start gap-1.5 text-xs text-success"
                      : "flex items-start gap-1.5 text-xs text-destructive"
                  }
                >
                  {result.ok ? (
                    <CheckCircle2 className="mt-0.5 size-3 shrink-0" />
                  ) : (
                    <TriangleAlert className="mt-0.5 size-3 shrink-0" />
                  )}
                  {/* The round-trip time comes back from the probe and was being
                      thrown away. It is also the plainest evidence the check
                      really went out to the provider rather than short-circuiting. */}
                  <span>
                    {result.ok
                      ? result.latency
                        ? t("row.testPassedIn", { ms: result.latency })
                        : t("row.testPassed")
                      : result.message}
                  </span>
                </p>
                {/* A failure with no next step leaves people re-clicking Test.
                    Wrong keys are the common cause, so the fix is offered here
                    instead of only in the overflow menu. */}
                {!result.ok && canManage ? (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7"
                    onClick={onReplace}
                  >
                    <KeyRound className="size-3" />
                    {t("row.replace")}
                  </Button>
                ) : null}
              </div>
            ) : (
              <StoredVerdict destination={destination} canManage={canManage} onReplace={onReplace} />
            )}
          </div>
        </div>
  
        {/* Wide screen only: its own column, right-aligned so the numbers line up
            down the list instead of floating wherever the name ends. */}
        <div className="hidden space-y-0.5 text-xs text-muted-foreground sm:block sm:min-w-40 sm:text-right">
          {facts}
        </div>
  
        {/* `ml-auto` is what drops these to their own line, right-aligned, once
            the identity block takes the full width on a phone. */}
        <div className="ml-auto flex shrink-0 items-center gap-1">
          {/* Nothing to test without keys — the probe would fail on every
              click and teach the user nothing. Disabled with the reason said
              out loud, rather than letting them discover it. */}
          <ReasonTooltip
            reason={
              !canManage
                ? t("noPermission")
                : !destination.has_credentials
                  ? t("row.testNeedsCredentials")
                  : null
            }
          >
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onTest}
              disabled={!canManage || testing || !destination.has_credentials}
            >
              <PlugZap className="size-3.5" />
              {t("row.test")}
            </Button>
          </ReasonTooltip>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button type="button" variant="ghost" size="icon" aria-label={t("row.actions")}>
                <MoreHorizontal className="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onSelect={onEdit} disabled={!canManage}>
                <Pencil className="size-3.5" />
                {t("row.edit")}
              </DropdownMenuItem>
              <DropdownMenuItem onSelect={onReplace} disabled={!canManage}>
                <KeyRound className="size-3.5" />
                {t("row.replace")}
              </DropdownMenuItem>
              <DropdownMenuItem onSelect={onDelete} disabled={!canManage} variant="destructive">
                <Trash2 className="size-3.5" />
                {t("row.delete")}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>
    </DisabledReasonProvider>
  );
}

/**
 * What the last probe found, from before this page was opened.
 *
 * Only rendered when there is no fresh result on screen — a test you just ran
 * outranks one from last month. `never_tested` gets said out loud rather than
 * left blank: a destination nobody has ever checked is the one most likely to
 * fail at 2am, and silence reads as "fine".
 */
function StoredVerdict({ destination, canManage, onReplace }) {
  const t = useTranslations("storage");
  const { status, last_test_success: success, last_tested_at_human: when } = destination;

  if (!status || status === "never_tested") {
    return (
      <p className="flex items-center gap-1.5 pt-0.5 text-xs text-muted-foreground">
        <CircleHelp className="size-3 shrink-0" />
        {t("row.neverTested")}
      </p>
    );
  }

  if (success) {
    return (
      <p className="flex items-center gap-1.5 pt-0.5 text-xs text-muted-foreground">
        <CheckCircle2 className="size-3 shrink-0 text-success" />
        {when ? t("row.lastTestedOk", { when }) : t("row.testPassed")}
      </p>
    );
  }

  return (
    <div className="space-y-1.5 pt-0.5">
      <p className="flex items-start gap-1.5 text-xs text-destructive">
        <TriangleAlert className="mt-0.5 size-3 shrink-0" />
        {/* Branching on the stable category, never on a message: the raw
            provider text is not sent, and would not be translatable if it were. */}
        <span>
          {destination.last_test_error === "invalid_credentials"
            ? t("row.failedCredentials", { when: when ?? "" })
            : t("row.failedUnreachable", { when: when ?? "" })}
        </span>
      </p>
      {destination.last_test_error === "invalid_credentials" && canManage ? (
        <Button type="button" variant="outline" size="sm" className="h-7" onClick={onReplace}>
          <KeyRound className="size-3" />
          {t("row.replace")}
        </Button>
      ) : null}
    </div>
  );
}
