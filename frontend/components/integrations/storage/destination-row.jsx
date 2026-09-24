import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { GoogleDriveConnect } from "@/components/integrations/storage/google-drive-connect";
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
import { describeDestination } from "@/lib/storage/providers";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ActionIcon } from "@/components/ui/action-icon";
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
/**
 * One sentence per failure category the API can report.
 *
 * Deliberately exhaustive rather than a two-way branch, and deliberately
 * fallback-to-generic rather than fallback-to-unreachable: a category added by
 * a later driver is one this build knows nothing about, and guessing it is a
 * network fault is how a host-key mismatch got shown as a firewall problem.
 */
const FAILURE_KEYS = {
  invalid_credentials: "failedCredentials",
  drive_personal: "failedDrivePersonal",
  drive_not_shared: "failedDriveNotShared",
  drive_folder_missing: "failedDriveFolderMissing",
  drive_not_a_folder: "failedDriveNotAFolder",
  drive_bad_key: "failedDriveBadKey",
  drive_quota: "failedDriveQuota",
  drive_incomplete: "failedDriveIncomplete",
  unreachable: "failedUnreachable",
  host_key_mismatch: "failedHostKey",
  invalid_private_key: "failedPrivateKey",
  root_missing: "failedRootMissing",
  mismatch: "failedMismatch",
};

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
  // Per-provider: "bucket/prefix" is meaningless for an FTP host, and an
  // empty bucket column beside a hostname is worse than no column at all.
  const { location, address } = describeDestination(destination);
  const isS3 = destination.provider === "s3";

  // Written once, placed twice — inside the content column on a phone, in its
  // own column on a wide screen. Two copies of this markup is how they drift.
  /*
   * A Drive destination exists before anyone has approved it.
   *
   * The panel told people "Not connected yet. Use Connect to approve access"
   * and then offered no Connect: the row's only recovery button was Replace
   * credentials, gated on a different failure, and the real button lived at the
   * bottom of the Edit dialog where nobody would look for it.
   *
   * Read from `config.connected` — the flag the API publishes for exactly this
   * decision — rather than from the failed-test category. That category is
   * currently wrong for this case anyway: the backend derives it with
   * `Str::after($key, 'storage.test.')` and this driver's key is
   * `storage.oauth.not_connected`, so the whole key comes through instead of
   * the word. Reported separately; the UI does not need it to be right.
   */
  const needsConnect =
    destination.provider === "google_drive_oauth" && destination.config?.connected === false;

  const facts = (
    <>
      <p className="text-foreground">
        {isS3 ? destination.config?.region || t("row.regionDefault") : destination.provider_title}
      </p>
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
              {/* Read from the API, not inferred. This used to match the
                  endpoint hostname against a list because the API stored
                  `driver: s3` for everything; `provider` is a real column now,
                  so the row states a fact instead of a guess. */}
              {destination.provider_title ? (
                <Badge variant="outline" className="font-normal">
                  {destination.provider_title}
                </Badge>
              ) : null}
              {/* Not "verified" — only that both secret columns are populated.
                  Whether they WORK is what Test answers. */}
              {destination.has_credentials ? (
                <Badge variant="muted">{t("row.credentialsSet")}</Badge>
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
  
            {location ? (
              <p className="truncate font-mono text-xs text-muted-foreground">{location}</p>
            ) : null}
            {address ? (
              <p className="truncate font-mono text-xs text-muted-foreground">{address}</p>
            ) : isS3 ? (
              <p className="text-xs text-muted-foreground">{t("row.awsDefault")}</p>
            ) : null}
  
            {/* Phone: region and age join the same indent as everything else
                rather than starting a new left edge at the card border. */}
            <div className="space-y-0.5 text-xs text-muted-foreground sm:hidden">{facts}</div>
  
            {testing ? (
              <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Loader2 className="size-3 animate-spin" />
                {t("row.testing")}
              </p>
            ) : needsConnect ? (
              /* Said once, where the action is. Not the red failed-test line
                 as well — "the test failed" and "you have not connected yet"
                 are the same fact told twice, and the second telling is the
                 one with a button. */
              <div className="space-y-1.5 pt-0.5">
                <p className="flex items-start gap-1.5 text-xs text-warning">
                  <TriangleAlert className="mt-0.5 size-3 shrink-0" />
                  {t("row.needsConnect")}
                </p>
                {canManage ? <GoogleDriveConnect destination={destination} compact /> : null}
              </div>
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
              <ActionIcon icon={PlugZap} pending={testing} className="size-3.5" />
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
            provider text is not sent, and would not be translatable if it were.

            Every category the API can return gets its own sentence. This used
            to be a two-way branch that rendered everything except
            `invalid_credentials` as "could not be reached" — so a CHANGED HOST
            KEY, the one failure that can mean someone else is answering,
            displayed as a network problem. An unknown category falls back to
            the generic failure rather than to "unreachable", because a build
            that has not heard of a category does not know it is a network
            one. */}
        <span>{t(`row.${FAILURE_KEYS[destination.last_test_error] ?? "failed"}`, { when: when ?? "" })}</span>
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
