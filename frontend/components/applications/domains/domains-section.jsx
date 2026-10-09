"use client";

import { useRef, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  Globe2,
  Plus,
  MoreHorizontal,
  RotateCw,
  Pencil,
  Star,
  Trash2,
  ShieldAlert,
  ShieldCheck,
  ShieldOff,
  CheckCircle2,
  CircleDashed,
  ArrowRight,
  ExternalLink,
} from "lucide-react";
import { cn } from "@/lib/utils";
import {
  verifyDomain,
  makePrimaryDomain,
  deleteDomain,
} from "@/lib/api/domains";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Caution } from "@/components/ui/caution";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/data-table/empty-state";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { CopyButton } from "@/components/ui/copy-button";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { AddDomainDialog } from "@/components/applications/domains/add-domain-dialog";
import { EditDomainDialog } from "@/components/applications/domains/edit-domain-dialog";

// Site types whose installer rewrites the stored address on a primary change (backend `syncUrl`).
const ADDRESS_SYNCED_TYPES = new Set([
  "wordpress", "akaunting", "craftcms", "mautic", "moodle",
  "n8n", "nextcloud", "nodebb", "prestashop", "statamic",
]);

const TYPE_VARIANT = {
  primary: "default",
  alias: "secondary",
  redirect: "outline",
};

// "covered" | "uncovered" | "unknown" for an ACTIVE certificate. "unknown" is explicit:
// callers want opposite defaults (visit link keeps https, confirm dialogs stay quiet).
function certificateCoverage(certificate, domain) {
  if (certificate?.status !== "active") return "unknown";
  // The certificate's own list first: `missing_domains` can be stale.
  if (certificate.domains?.length) {
    const name = String(domain ?? "").toLowerCase();
    const covered = certificate.domains.some((entry) => {
      const on = String(entry).toLowerCase();
      if (on === name) return true;
      // *.example.com covers one label, not the apex and not deeper names.
      return on.startsWith("*.") && name.endsWith(on.slice(1)) && !name.slice(0, -on.length + 1).includes(".");
    });
    return covered ? "covered" : "uncovered";
  }
  if (certificate.missing_domains?.length) {
    return certificate.missing_domains.includes(domain) ? "uncovered" : "covered";
  }
  return "unknown";
}

// Null while a certificate is issuing or failed; the SSL tab reports that once.
// "Secured" only when visitors actually reach this server: a covered name whose DNS points
// elsewhere is not served by this certificate (a Cloudflare-proxied name still is).
function sslRowState(certificate, coverage, domain) {
  if (!certificate) return { key: "none", tone: "text-muted-foreground", icon: ShieldOff };
  if (certificate.status !== "active") return null;
  if (coverage === "covered" && !domain.dns_verified && !domain.behind_proxy) {
    return { key: "coveredNotPointing", tone: "text-muted-foreground", icon: ShieldCheck };
  }
  if (coverage === "covered") return { key: "secured", tone: "text-success", icon: ShieldCheck };
  if (coverage === "uncovered") return { key: "notCovered", tone: "text-warning", icon: ShieldAlert };
  return null;
}

// Domain | DNS | HTTPS | actions, shared by the heading row and every domain row.
// The actions column is fixed, not `auto`: header and rows are separate grids, and `auto`
// sized each to its own content, so the headings drifted off their columns.
const ROW_GRID = "md:grid-cols-[minmax(0,1fr)_10rem_10rem_13rem] md:items-start";

export function DomainsSection({
  appId,
  domains = [],
  canManage = false,
  serverIp = null,
  secured = false,
  siteType = null,
  // The current certificate, or null; the Add dialog's advice depends on its type.
  certificate = null,
}) {
  const t = useTranslations("applications.domains");
  const { pending: refreshing, refreshThen, refreshAndWait } = useRefresh();

  const [addOpen, setAddOpen] = useState(false);
  const [promoteTarget, setPromoteTarget] = useState(null);
  // Copied onto the target when the dialog opens; the list changes during the re-read.
  const currentPrimary = domains.find((domain) => domain.type === "primary");
  const [editTarget, setEditTarget] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [pending, setPending] = useState(false);
  const [verifying, setVerifying] = useState({});
  // The removed row's ⋯ button is gone, so focus goes to Add domain instead.
  const removed = useRef(false);

  const coverageOf = (domain) => certificateCoverage(certificate, domain);

  // Shared by the header and the empty state.
  const addButton = canManage ? (
    <Button onClick={() => setAddOpen(true)} data-domains-add>
      <Plus className="size-4" />
      {t("add.action")}
    </Button>
  ) : null;

  async function onVerify(domain) {
    setVerifying((v) => ({ ...v, [domain.domain]: true }));
    try {
      // Report the outcome: an unchanged row would look like nothing happened.
      const result = await verifyDomain(appId, domain.domain);
      await refreshAndWait();
      if (result?.dns_verified) {
        toast.success(t("toast.verified", { domain: domain.domain }));
      } else {
        toast.info(t("toast.notPointing", { domain: domain.domain }));
      }
    } catch (error) {
      toast.error(apiMessage(error, t("toast.verifyFailed")));
    } finally {
      setVerifying((v) => {
        const next = { ...v };
        delete next[domain.domain];
        return next;
      });
    }
  }

  async function confirmPromote() {
    setPending(true);
    try {
      await makePrimaryDomain(appId, promoteTarget.domain);
      // Closes after the list re-reads, so the new primary is already shown.
      refreshThen(() => {
        toast.success(t("toast.promoted", { domain: promoteTarget.domain }));
        setPromoteTarget(null);
      });
    } catch (error) {
      toast.error(apiMessage(error, t("toast.promoteFailed")));
    } finally {
      setPending(false);
    }
  }

  async function confirmDelete() {
    const target = deleteTarget.domain;
    setPending(true);
    try {
      await deleteDomain(appId, target);
      refreshThen(() => {
        removed.current = true;
        toast.success(t("toast.removed", { domain: target }));
        setDeleteTarget(null);
      });
    } catch (error) {
      // Already removed elsewhere: treat as success.
      if (error?.response?.status === 404) {
        refreshThen(() => {
          removed.current = true;
          toast.info(t("toast.removedAlready", { domain: target }));
          setDeleteTarget(null);
        });
        return;
      }
      toast.error(apiMessage(error, t("toast.removeFailed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 space-y-0 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-1">
          <CardTitle className="text-base font-semibold">
            {t("sectionTitle")}
          </CardTitle>
          <CardDescription>{t("sectionSubtitle")}</CardDescription>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <RefreshButton />
          {addButton}
        </div>
      </CardHeader>

      <CardContent>
        {domains.length === 0 ? (
          <EmptyState
            icon={Globe2}
            title={t("emptyTitle")}
            description={t("emptyBody")}
            action={addButton}
          />
        ) : (
          /* Edge to edge in the card, as a list: one row per name, the same columns on
             every row so several domains scan like a table. */
          <div className="-mx-(--card-spacing) -mb-(--card-spacing) border-t">
            <div
              aria-hidden
              className={cn(ROW_GRID, "hidden gap-x-4 border-b bg-muted/40 px-5 py-3 text-[13px] font-medium text-muted-foreground md:grid")}
            >
              <span>{t("columns.domain")}</span>
              <span>{t("columns.dns")}</span>
              <span>{t("columns.https")}</span>
              <span />
            </div>
            <div className="divide-y">
            {domains.map((domain) => {
              const isPrimary = domain.type === "primary";
              const isVerifying = Boolean(verifying[domain.domain]);
              const ssl = sslRowState(certificate, coverageOf(domain.domain), domain);
              const SslIcon = ssl?.icon;
              return (
                // Phone: the name across the full width, then the two states as pills with the
                // buttons at the end of that line.
                // From md: the four columns of the heading row.
                <div
                  key={domain.id}
                  className={cn("grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-2.5 px-4 py-3.5 md:gap-x-4 md:px-5", ROW_GRID)}
                >
                  <div className="col-span-2 flex min-w-0 items-start gap-3 md:col-span-1">
                    <span
                      className="hidden size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground md:flex"
                      aria-hidden
                    >
                      <Globe2 className="size-4" />
                    </span>
                    <div className="min-w-0 flex-1 space-y-1">
                      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        {/* Inline, so the copy button follows the last character of the name
                            however the name wraps. */}
                        <span className="min-w-0 font-mono text-sm break-all">
                          {domain.domain}
                          <CopyButton
                            value={domain.domain}
                            label={t("copyDomain")}
                            className="ml-1 inline-flex size-6 align-middle"
                          />
                        </span>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <span className="inline-flex">
                              <Badge variant={TYPE_VARIANT[domain.type] ?? "secondary"} className="font-normal">
                                {domain.type_title ?? domain.type}
                              </Badge>
                            </span>
                          </TooltipTrigger>
                          <TooltipContent>{t(`type.${domain.type}Hint`)}</TooltipContent>
                        </Tooltip>
                        {domain.is_test ? (
                          <Badge variant="outline" className="font-normal text-muted-foreground">
                            {t("testDomain")}
                          </Badge>
                        ) : null}
                      </div>

                      {domain.type === "redirect" && domain.redirect_to ? (
                        <p className="flex items-center gap-1 text-xs text-muted-foreground">
                          <ArrowRight className="size-3" />
                          <span className="truncate font-mono">{domain.redirect_to}</span>
                          {domain.redirect_status ? <span>· {domain.redirect_status}</span> : null}
                        </p>
                      ) : null}

                      {/* Behind Cloudflare: a common support question. */}
                      {domain.behind_proxy ? (
                        <p className="flex items-start gap-1.5 text-xs text-warning">
                          <ShieldAlert className="mt-0.5 size-3.5 shrink-0" />
                          <span>{t("dns.behindProxy")}</span>
                        </p>
                      ) : null}

                      {/* The A-record target as a next step. Skipped for proxied names and test domains (nip.io). */}
                      {!domain.dns_verified && !domain.behind_proxy && !domain.is_test ? (
                        serverIp ? (
                          <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                            <span>{t("dns.pointLabel")}</span>
                            <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-foreground">{serverIp}</code>
                            <CopyButton value={serverIp} className="size-6" />
                          </p>
                        ) : (
                          <p className="text-xs text-muted-foreground">{t("dns.pointGeneric")}</p>
                        )
                      ) : null}
                    </div>
                  </div>

                  {/* Both states on one line under the name on a phone (as pills); from md
                      `contents` makes each its own column. */}
                  <div className="row-start-2 flex flex-wrap items-center gap-1.5 md:contents">
                  <p
                    className={cn(
                      "flex items-start gap-1.5 text-xs max-md:rounded-full max-md:px-2 max-md:py-0.5 md:pt-2",
                      domain.dns_verified ? "text-success max-md:bg-success-soft" : "text-muted-foreground max-md:bg-muted",
                    )}
                  >
                    {domain.dns_verified ? (
                      <CheckCircle2 className="mt-px size-3.5 shrink-0" />
                    ) : (
                      <CircleDashed className="mt-px size-3.5 shrink-0" />
                    )}
                    <span className="min-w-0">
                      {domain.dns_verified ? t("dns.verified") : t("dns.unverified")}
                      {domain.dns_resolved_ip ? (
                        <span className="block font-mono text-muted-foreground max-md:hidden">{domain.dns_resolved_ip}</span>
                      ) : null}
                    </span>
                  </p>

                  {/* HTTPS status for this name. */}
                  <p
                    className={cn(
                      "flex items-start gap-1.5 text-xs max-md:rounded-full max-md:px-2 max-md:py-0.5 md:pt-2",
                      ssl?.tone,
                      { secured: "max-md:bg-success-soft", notCovered: "max-md:bg-warning-soft" }[ssl?.key] ?? "max-md:bg-muted",
                      !ssl && "max-md:hidden",
                    )}
                  >
                    {ssl ? (
                      <>
                        <SslIcon className="mt-px size-3.5 shrink-0" aria-hidden />
                        <span>{t(`sslRow.${ssl.key}`)}</span>
                      </>
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                  </p>
                  </div>

                  <div className="col-start-2 row-start-2 flex shrink-0 items-center gap-0.5 self-center md:col-auto md:row-auto md:justify-end md:gap-1 md:self-start">
                    {/* https only when the certificate covers this name. The slot is always held so buttons align. */}
                    <span className="inline-flex size-8 shrink-0 items-center justify-center">
                      {domain.dns_verified && domain.type !== "redirect" ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              asChild
                              variant="ghost"
                              size="icon"
                              className="size-8"
                            >
                              <a
                                href={`${secured && coverageOf(domain.domain) !== "uncovered" ? "https" : "http"}://${domain.domain}`}
                                target="_blank"
                                rel="noreferrer noopener"
                              >
                                <ExternalLink className="size-4" />
                                <span className="sr-only">{t("openSite")}</span>
                              </a>
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent>{t("openSite")}</TooltipContent>
                        </Tooltip>
                      ) : null}
                    </span>
                    {/* Not behind `canManage`: the verify route is view-level and changes nothing. */}
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={isVerifying}
                      onClick={() => onVerify(domain)}
                    >
                      <RotateCw className={isVerifying ? "size-3.5 animate-spin" : "size-3.5"} />
                      {/* Icon only on a phone, so the name keeps the width. */}
                      <span className="max-md:sr-only">{t("dns.verify")}</span>
                    </Button>
                    {canManage ? (
                      <span className="inline-flex size-8 shrink-0 items-center justify-center">
                        {!isPrimary ? (
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button
                              variant="ghost"
                              size="icon"
                              className="size-8"
                            >
                              <MoreHorizontal className="size-4" />
                              <span className="sr-only">{t("rowActions")}</span>
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="min-w-44">
                            {/* Not offered on the primary (the server refuses). */}
                            <DropdownMenuItem onSelect={() => setEditTarget(domain)}>
                              <Pencil className="size-4" />
                              {t("edit.action")}
                            </DropdownMenuItem>
                            {domain.type === "alias" ? (
                              <DropdownMenuItem
                                onSelect={() => setPromoteTarget({ ...domain, from: currentPrimary?.domain })}
                              >
                                <Star className="size-4" />
                                {t("makePrimary")}
                              </DropdownMenuItem>
                            ) : null}
                            <DropdownMenuItem
                              variant="destructive"
                              onSelect={() => setDeleteTarget(domain)}
                            >
                              <Trash2 className="size-4" />
                              {t("remove")}
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                        ) : null}
                      </span>
                    ) : null}
                  </div>
                </div>
              );
            })}
            </div>
          </div>
        )}
      </CardContent>

      {canManage ? (
        <>
          <AddDomainDialog
            appId={appId}
            open={addOpen}
            onOpenChange={setAddOpen}
            serverIp={serverIp}
            certificate={certificate}
          />
          {/* One instance for the list; re-seeds from `editTarget` on each open. */}
          <EditDomainDialog
            appId={appId}
            domain={editTarget}
            open={Boolean(editTarget)}
            onOpenChange={(o) => !o && setEditTarget(null)}
          />
        </>
      ) : null}

      <ConfirmDialog
        open={Boolean(promoteTarget)}
        onOpenChange={(o) => !o && setPromoteTarget(null)}
        icon={Star}
        title={t("promote.title", { domain: promoteTarget?.domain ?? "" })}
        description={t("promote.body")}
        cancelLabel={t("cancel")}
        confirmLabel={t("makePrimary")}
        pending={pending || refreshing}
        onConfirm={confirmPromote}
      >
        {/* Shows the swap; the title cannot show the name being replaced. */}
        {promoteTarget?.from ? (
          <dl className="grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-4 gap-y-1.5 rounded-lg border p-3">
            <dt className="text-xs text-muted-foreground">
              {t("promote.nowLabel")}
            </dt>
            <dd className="font-mono text-sm break-all text-muted-foreground">
              {promoteTarget.from}
            </dd>
            <dt className="text-xs text-muted-foreground">
              {t("promote.afterLabel")}
            </dt>
            <dd className="font-mono text-sm break-all">
              {promoteTarget?.domain}
            </dd>
          </dl>
        ) : null}

        {/* One line per consequence. */}
        <ul className="list-disc space-y-1.5 pl-5 text-sm text-muted-foreground">
          {promoteTarget?.from ? (
            <li>
              {t("promote.keepsServing", { domain: promoteTarget.from })}
            </li>
          ) : null}
          {/* The installer's syncUrl rewrites the stored address (e.g. WordPress siteurl). No files are renamed. */}
          {promoteTarget && ADDRESS_SYNCED_TYPES.has(siteType) ? (
            <li>{t("promote.updatesAddress", { domain: promoteTarget.domain })}</li>
          ) : null}
        </ul>

        {/* Not on the certificate, so browsers will refuse it over HTTPS. */}
        {promoteTarget && coverageOf(promoteTarget.domain) === "uncovered" ? (
          <Caution>
            {t("promote.notOnCertificate", { domain: promoteTarget.domain })}
          </Caution>
        ) : null}
      </ConfirmDialog>

      <ConfirmDialog
        open={Boolean(deleteTarget)}
        onOpenChange={(o) => !o && setDeleteTarget(null)}
        icon={Trash2}
        tone="destructive"
        title={t("removeConfirm.title", { domain: deleteTarget?.domain ?? "" })}
        description={t("removeConfirm.body")}
        cancelLabel={t("cancel")}
        confirmLabel={t("remove")}
        pending={pending || refreshing}
        onConfirm={confirmDelete}
        onCloseAutoFocus={(event) => {
          if (!removed.current) return;
          removed.current = false;
          event.preventDefault();
          document.querySelector("[data-domains-add]")?.focus();
        }}
      >
        {/* certbot fails the whole renewal if any name is unreachable; nothing renews an uploaded one. */}
        {deleteTarget && certificate?.renewable && coverageOf(deleteTarget.domain) === "covered" ? (
          <Caution>
            {t("removeConfirm.onCertificate", { domain: deleteTarget.domain })}
          </Caution>
        ) : null}
      </ConfirmDialog>
    </Card>
  );
}
