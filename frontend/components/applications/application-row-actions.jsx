"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
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
  // Only the list passes this. The application's own dashboard already carries
  // a full Magic Login button in its header, so defaulting to false is what
  // keeps the same action from appearing twice on that page.
  canMagicLogin = false,
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
  const { refreshThen } = useRefresh();
  const [retrying, setRetrying] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  // Set by the items that open a dialog. Only then is focus kept off the ⋯
  // button as the menu closes (the dialog takes it); an Escape or a click
  // away used to drop keyboard focus at the top of the page.
  const openingDialog = useRef(false);
  /*
   * One administrator signs straight in; none or several opens the picker.
   * The hook owns that decision and the tab, because the row menu and the site
   * dashboard's button must behave identically and the sequence is easy to
   * half-copy. See use-magic-login.js.
   */
  const magicLogin = useMagicLogin(application.id);
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
  // WordPress only, and only while the site is actually being served. The
  // catalog the list reads is not filtered by site type — unlike the
  // Dashboard's, which is fetched for one application — so the row has to ask
  // the question itself.
  const showMagicLogin =
    canMagicLogin &&
    application.site_type === "wordpress" &&
    application.status === "active";

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
      // After the refresh lands, so the badge and the toast agree.
      refreshThen(() => {
        toast.success(t("pause.resumed", { name: application.name }));
        setResuming(false);
      });
    } catch (error) {
      // Includes the 422 for a site somebody already resumed elsewhere; the
      // API's sentence says that better than a generic failure would.
      toast.error(apiMessage(error, t("pause.resumeFailed")));
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

          {/* Beside Visit, because they are the same act with different
              credentials: one opens the site as a visitor, the other as its
              administrator — so it belongs in the navigation group rather than
              in a band of its own.

              No separator here. The `canManage` group below draws one whenever
              anything sits above it, and this block adding a second produced
              two rules between Magic Login and Pause site. A group that ends
              with a separator AND a group that begins with one cannot both be
              right; the one that knows what follows it wins. */}
          {showMagicLogin ? (
            <DropdownMenuItem
              /*
               * `preventDefault` so Radix does not close the menu before the
               * click has been used. `window.open` is allowed by the gesture
               * this handler is running inside, and a menu that tears itself
               * down first takes that with it.
               */
              onSelect={(event) => {
                event.preventDefault();
                setMenuOpen(false);
                magicLogin.start();
                openingDialog.current = true;
              }}
            >
              <KeyRound className="size-4" />
              {t("magicLogin.action")}
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
              {/* The only property of an application the API can update. Sits
                  with the navigation because it is what "edit this app" means
                  here — there is no other editable field and no settings
                  screen to send anyone to. */}
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
              {showNavigation || showRetry || showMagicLogin ? <DropdownMenuSeparator /> : null}
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
      {/* Mounted only once it has been asked for. Every row of this list would
          otherwise carry a dialog nobody opened — and the list renders ten. */}
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
