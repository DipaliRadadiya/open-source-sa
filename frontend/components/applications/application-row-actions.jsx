"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
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

/**
 * The screens worth reaching from the header menu, in the order a site is
 * usually worked on. Keyed rather than passed whole because the caller is a
 * server component: a Lucide component cannot cross that boundary, so the
 * server sends the keys it has permission for and the icon is resolved here.
 */
const SHORTCUT_ICONS = {
  files: FolderTree,
  domains: Globe2,
  backups: Archive,
};

/**
 * Row menu for one application. Open and Visit are reads and stay available to
 * view-only users; Retry and Delete are writes and need `manage`.
 *
 * Visit is only offered while the site is actually being served — a link that
 * lands on a connection error teaches people the panel is lying.
 */
export function ApplicationRowActions({
  application,
  canManage = false,
  // On the application's own dashboard page, "Open dashboard" points at the
  // current page and "Visit" duplicates the header button — hide both there.
  showNavigation = true,
  // Keys of the app screens this user may open, already permission-filtered by
  // the server. Only the detail header passes these — on the list, five extra
  // links per row would bury Delete under navigation nobody opens a row menu
  // for.
  shortcuts = [],
  // Called after a successful delete, before redirecting (if redirectTo is set).
  // Useful on the list page to refresh the table immediately.
  afterDelete,
  // Where to navigate after deleting from a page where staying doesn't make sense.
  // Passed through to DeleteApplicationDialog.
  redirectTo,
}) {
  const t = useTranslations("applications");
  const router = useRouter();
  const [retrying, setRetrying] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  /*
   * Close when the row changes underneath an open menu.
   *
   * Retry holds the menu open on purpose so the item can say "Retrying…"
   * (see its onSelect). The moment the server accepts it the status leaves
   * `failed`, the Retry item disappears — and what is left open is a menu
   * about a site in a state it is no longer in: Visit greyed, Delete offered
   * while the API refuses it with a 503 for a provisioning site.
   *
   * Keyed on status rather than on the retry, so the same holds when
   * provisioning finishes, or somebody else pauses the site — the list polls
   * every four seconds, so any of those can land while the menu is open.
   *
   * Render-phase sync, the same shape `workers-panel` uses: an effect would
   * paint the stale menu once before closing it.
   */
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

  // Nothing to offer (view-only, on the detail page) → no empty ⋯ trigger.
  if (!showNavigation && !showRetry && !canManage && shortcuts.length === 0) return null;

  // No dialog: putting a site back is what the reader already decided when
  // they pressed it, and there is nothing to warn about in restoring service.
  async function resume() {
    setResuming(true);
    try {
      await enableApplication(application.id);
      toast.success(t("pause.resumed", { name: application.name }));
      router.refresh();
    } catch (error) {
      // Includes the 422 for a site somebody already resumed elsewhere; the
      // API's sentence says that better than a generic failure would.
      toast.error(apiMessage(error, t("pause.resumeFailed")));
    } finally {
      setResuming(false);
    }
  }

  async function retry() {
    setRetrying(true);
    try {
      await retryProvisioning(application.id);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("details.failedAt", { step: provisionStepLabel(application.failed_step, t, "details.") })));
    } finally {
      setRetrying(false);
    }
  }

  return (
    <div className="text-right">
      <DropdownMenu open={menuOpen} onOpenChange={setMenuOpen}>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8">
            {retrying ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <MoreHorizontal className="size-4" />
            )}
            <span className="sr-only">{t("actions.label")}</span>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-52" onCloseAutoFocus={(e) => e.preventDefault()}>
          {showNavigation ? (
            <>
              <DropdownMenuItem asChild>
                <Link href={href}>
                  <LayoutDashboard className="size-4" />
                  {t("actions.open")}
                </Link>
              </DropdownMenuItem>

              {/* `url`, never assembled from `domain`: the API serves http://
                  until the site has a servable certificate, which every site
                  lacks for the first few minutes of its life. An assumed
                  https:// is a connection refused, because a site with no
                  certificate has no TLS listener at all. */}
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
              {/* The only property of an application the API can update. Sits
                  with the navigation because it is what "edit this app" means
                  here — there is no other editable field and no settings
                  screen to send anyone to. */}
              {canManage ? (
                <DropdownMenuItem onSelect={() => setWebRootOpen(true)}>
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
                // Radix closes the menu on select, which left the "Retrying…"
                // branch below unreachable — the trigger's spinner was the only
                // sign, and on a long row that is easy to miss. Held open so the
                // item says it itself.
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
              {showNavigation || showRetry ? <DropdownMenuSeparator /> : null}
              {/* Above Delete and outside the destructive group: pausing is
                  reversible in one click and must not read like the row that
                  ends the site. Which control appears is decided in
                  `pauseControl` — see there for why the paused check comes
                  first and why a provisioning site is offered neither. */}
              {pauseAction === "resume" ? (
                <DropdownMenuItem
                  disabled={resuming}
                  onSelect={(event) => {
                    // Held open so the item can say "Resuming…" itself; Radix
                    // would otherwise close the menu and leave the trigger as
                    // the only sign anything is happening.
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
                <DropdownMenuItem onSelect={() => setPauseOpen(true)}>
                  <PauseCircle className="size-4" />
                  {t("pause.action")}
                </DropdownMenuItem>
              ) : null}
              <DropdownMenuItem variant="destructive" onSelect={() => setDeleteOpen(true)}>
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
