"use client";

import { useRef, useState } from "react";
import Link from "@/components/ui/app-link";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { provisionStepLabel } from "@/lib/applications/provision-steps";
import { toast } from "sonner";
import {
  Archive,
  ExternalLink,
  FolderTree,
  Globe2,
  LayoutDashboard,
  Loader2,
  KeyRound,
  MoreHorizontal,
  PauseCircle,
  Pencil,
  PlayCircle,
  RotateCw,
  Trash2,
} from "lucide-react";
import { enableApplication, retryProvisioning } from "@/lib/api/applications";
import { pauseControl } from "@/lib/applications/pause-control";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";
import { DeleteApplicationDialog } from "@/components/applications/delete-application-dialog";
import { PauseApplicationDialog } from "@/components/applications/pause-application-dialog";
import { WebRootDialog } from "@/components/applications/web-root-dialog";
import { MagicLoginDialog } from "@/components/applications/magic-login-dialog";
import { useMagicLogin } from "@/components/applications/use-magic-login";

// Keyed because icons cannot cross the server-component boundary.
const SHORTCUT_ICONS = {
  files: FolderTree,
  domains: Globe2,
  backups: Archive,
};

// Open and Visit are reads; Retry and Delete need `manage`. Visit only while served.
export function ApplicationRowActions({
  application,
  canManage = false,
  // Only the list passes this; the app dashboard has its own Magic Login button.
  canMagicLogin = false,
  // On the app's own dashboard, "Open dashboard" and "Visit" would be redundant.
  showNavigation = true,
  // Permission-filtered keys of app screens. Only the detail header passes these,
  // to keep the list's row menu short.
  shortcuts = [],
  // Called after a successful delete, before redirecting (if redirectTo is set).
  afterDelete,
  // Where to navigate after deleting. Passed through to DeleteApplicationDialog.
  redirectTo,
}) {
  const t = useTranslations("applications");
  const { refreshThen } = useRefresh();
  const [retrying, setRetrying] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  // Set by items that open a dialog, so only then is focus kept off the ⋯ button
  // as the menu closes (the dialog takes it).
  const openingDialog = useRef(false);
  // Signs straight in for one administrator, otherwise opens the picker. Shared
  // with the site dashboard's button; see use-magic-login.js.
  const magicLogin = useMagicLogin(application.id);
  // WordPress only, and only while the site is served. The list's catalog is not
  // filtered by site type, so the row checks itself.
  const showMagicLogin =
    canMagicLogin &&
    application.site_type === "wordpress" &&
    application.status === "active";

  // Close the menu when the status changes underneath it. Render-phase sync: an effect
  // would paint the stale menu once first.
  const [seenStatus, setSeenStatus] = useState(application.status);
  if (seenStatus !== application.status) {
    setSeenStatus(application.status);
    if (menuOpen) setMenuOpen(false);
  }
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [webRootOpen, setWebRootOpen] = useState(false);
  const [pauseOpen, setPauseOpen] = useState(false);
  const [resuming, setResuming] = useState(false);

  const href = `/applications/${application.id}`;
  const canVisit = application.status === "active";
  const canRetry = application.status === "failed";
  const showRetry = canManage && canRetry;
  const pauseAction = pauseControl(application, { canManage });

  if (!showNavigation && !showRetry && !canManage && shortcuts.length === 0) return null;

  // No confirmation: restoring service needs no warning.
  async function resume() {
    setResuming(true);
    try {
      await enableApplication(application.id);
      // After the refresh, so badge and toast agree. Closes the menu here because
      // pause/resume change `disabled_at`, not `status`.
      refreshThen(() => {
        toast.success(t("pause.resumed", { name: application.name }));
        setResuming(false);
        setMenuOpen(false);
      });
    } catch (error) {
      // Includes the 422 for a site already resumed elsewhere; the API's message is
      // clearer than a generic failure.
      toast.error(apiMessage(error, t("pause.resumeFailed")));
      setResuming(false);
    }
  }

  async function retry() {
    setRetrying(true);
    try {
      await retryProvisioning(application.id);
      // Keep "Retrying…" until the row itself shows Provisioning.
      refreshThen(() => setRetrying(false));
    } catch (error) {
      toast.error(apiMessage(error, t("details.failedAt", { step: provisionStepLabel(application.failed_step, t, "details.") })));
      setRetrying(false);
    }
  }

  return (
    <div className="text-right">
      <DropdownMenu open={menuOpen} onOpenChange={setMenuOpen}>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8">
            {retrying || magicLogin.pending ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <MoreHorizontal className="size-4" />
            )}
            <span className="sr-only">{t("actions.label")}</span>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
          align="end"
          className="w-52"
          onCloseAutoFocus={(e) => {
            if (!openingDialog.current) return;
            openingDialog.current = false;
            e.preventDefault();
          }}
        >
          {showNavigation ? (
            <>
              <DropdownMenuItem asChild>
                <Link href={href}>
                  <LayoutDashboard className="size-4" />
                  {t("actions.open")}
                </Link>
              </DropdownMenuItem>

              {/* Uses `url`, never built from `domain`: the API serves http:// until the
                  site has a certificate, and an assumed https:// would be refused. */}
              <MenuItemHint hint={canVisit ? null : t("actions.visitHint")}>
                <DropdownMenuItem asChild={canVisit} disabled={!canVisit}>
                  {canVisit ? (
                    <a href={application.url ?? `https://${application.domain}`} target="_blank" rel="noreferrer">
                      <ExternalLink className="size-4" />
                      {t("actions.visit")}
                    </a>
                  ) : (
                    <>
                      <ExternalLink className="size-4" />
                      {t("actions.visit")}
                    </>
                  )}
                </DropdownMenuItem>
              </MenuItemHint>
            </>
          ) : null}

          {/* Grouped with Visit: the same act with admin credentials. No separator
              here; the `canManage` group below draws its own. */}
          {showMagicLogin ? (
            <DropdownMenuItem
              disabled={magicLogin.pending}
              /* Held open, saying "Signing you in…", until WordPress opens or
                 the picker takes over (same pattern as Retry). */
              onSelect={(event) => {
                event.preventDefault();
                openingDialog.current = true;
                magicLogin.start().then(() => setMenuOpen(false));
              }}
            >
              {magicLogin.pending ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <KeyRound className="size-4" />
              )}
              {magicLogin.phase === "fetching"
                ? t("magicLogin.fetchingUsers")
                : magicLogin.phase === "signing"
                  ? t("magicLogin.redirecting")
                  : t("magicLogin.action")}
            </DropdownMenuItem>
          ) : null}

          {shortcuts.length > 0 ? (
            <>
              {shortcuts.map((key) => {
                const Icon = SHORTCUT_ICONS[key];
                return (
                  <DropdownMenuItem key={key} asChild>
                    <Link href={`${href}/${key}`} prefetch={false}>
                      <Icon className="size-4" />
                      {t(`actions.go.${key}`)}
                    </Link>
                  </DropdownMenuItem>
                );
              })}
              {/* The only application property the API can update. */}
              {canManage ? (
                <DropdownMenuItem onSelect={() => { openingDialog.current = true; setWebRootOpen(true); }}>
                  <Pencil className="size-4" />
                  {t("webRoot.title")}
                </DropdownMenuItem>
              ) : null}
              <DropdownMenuSeparator />
            </>
          ) : null}

          {showRetry ? (
            <DropdownMenuItem
              disabled={retrying}
              onSelect={(event) => {
                // Radix closes the menu on select; hold it open so the item can show "Retrying…".
                event.preventDefault();
                retry();
              }}
            >
              {retrying ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <RotateCw className="size-4" />
              )}
              {retrying ? t("details.retrying") : t("details.retry")}
            </DropdownMenuItem>
          ) : null}

          {canManage ? (
            <>
              {showNavigation || showRetry || showMagicLogin ? <DropdownMenuSeparator /> : null}
              {/* Kept out of the destructive group: pausing is reversible. See
                  `pauseControl` for which control appears. */}
              {pauseAction === "resume" ? (
                <DropdownMenuItem
                  disabled={resuming}
                  onSelect={(event) => {
                    // Hold the menu open so the item can show "Resuming…".
                    event.preventDefault();
                    resume();
                  }}
                >
                  {resuming ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <PlayCircle className="size-4" />
                  )}
                  {resuming ? t("pause.resuming") : t("pause.resume")}
                </DropdownMenuItem>
              ) : pauseAction === "pause" ? (
                <DropdownMenuItem onSelect={() => { openingDialog.current = true; setPauseOpen(true); }}>
                  <PauseCircle className="size-4" />
                  {t("pause.action")}
                </DropdownMenuItem>
              ) : null}
              <DropdownMenuItem variant="destructive" onSelect={() => { openingDialog.current = true; setDeleteOpen(true); }}>
                <Trash2 className="size-4" />
                {t("actions.delete")}
              </DropdownMenuItem>
            </>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>

      <WebRootDialog
        application={application}
        open={webRootOpen}
        onOpenChange={setWebRootOpen}
      />
      {/* Mounted only when needed, so each row does not carry an unopened dialog. */}
      {magicLogin.choice ? (
        <MagicLoginDialog
          appId={application.id}
          admins={magicLogin.choice.admins}
          open
          onOpenChange={(next) => !next && magicLogin.closeChoice()}
        />
      ) : null}

      <PauseApplicationDialog
        application={application}
        open={pauseOpen}
        onOpenChange={setPauseOpen}
      />

      <DeleteApplicationDialog
        application={application}
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        afterDelete={afterDelete}
        redirectTo={redirectTo}
      />
    </div>
  );
}
