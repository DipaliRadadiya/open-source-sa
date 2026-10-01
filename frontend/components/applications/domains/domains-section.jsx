"use client";

import { useState } from "react";
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

// Site types whose installer rewrites the application's own stored address
// when the primary domain changes (`syncUrl` in the backend installers).
const ADDRESS_SYNCED_TYPES = new Set([
  "wordpress", "akaunting", "craftcms", "mautic", "moodle",
  "n8n", "nextcloud", "nodebb", "prestashop", "statamic",
]);

const TYPE_VARIANT = {
  primary: "default",
  alias: "secondary",
  redirect: "outline",
};

/**
 * Whether an ACTIVE certificate covers this exact name: `"covered"`,
 * `"uncovered"`, or `"unknown"`. "Unknown" is explicit because callers want
 * opposite defaults: the visit link keeps https, confirm dialogs stay quiet.
 * Reads `domains` first, `missing_domains` as fallback.
 */
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

/**
 * Whether this name is served over HTTPS, for its row. Null while a
 * certificate is issuing or failed; the SSL tab reports that once.
 */
function sslRowState(certificate, coverage) {
  if (!certificate) return { key: "none", tone: "text-muted-foreground", icon: ShieldOff };
  if (certificate.status !== "active") return null;
  if (coverage === "covered") return { key: "secured", tone: "text-success", icon: ShieldCheck };
  if (coverage === "uncovered") return { key: "notCovered", tone: "text-warning", icon: ShieldAlert };
  return null;
}

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
  // Copied onto the target when the dialog opens, since the list changes
  // while the dialog stays open through the re-read.
  const currentPrimary = domains.find((domain) => domain.type === "primary");
  const [editTarget, setEditTarget] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [pending, setPending] = useState(false);
  const [verifying, setVerifying] = useState({});

  const coverageOf = (domain) => certificateCoverage(certificate, domain);

  // Shared by the header and the empty state.
  const addButton = canManage ? (
    <Button onClick={() => setAddOpen(true)}>
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
        toast.success(t("toast.removed", { domain: target }));
        setDeleteTarget(null);
      });
    } catch (error) {
      // Already removed elsewhere: treat as success.
      if (error?.response?.status === 404) {
        refreshThen(() => {
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
          /* No border: the rows already sit inside a Card. */
          <div className="-mx-(--card-spacing) -mb-(--card-spacing) divide-y overflow-hidden border-t">
            {domains.map((domain) => {
              const isPrimary = domain.type === "primary";
              const isVerifying = Boolean(verifying[domain.domain]);
              return (
                <div key={domain.id} className="flex flex-wrap items-start gap-3 p-4">
                  {/* Tinted chip, matching the SSL tab's tiles. */}
                  <span
                    className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                    aria-hidden
                  >
                    <Globe2 className="size-4" />
                  </span>

                  <div className="min-w-40 flex-1 space-y-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                      {/* Name and copy button wrap as one unit. */}
                      <span className="flex min-w-0 items-center gap-2">
                        <span className="truncate font-mono text-sm">
                          {domain.domain}
                        </span>
                        <CopyButton
                          value={domain.domain}
                          label={t("copyDomain")}
                          className="size-6 shrink-0"
                        />
                      </span>
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <span className="inline-flex">
                            <Badge
                              variant={TYPE_VARIANT[domain.type] ?? "secondary"}
                              className="font-normal"
                            >
                              {domain.type_title ?? domain.type}
                            </Badge>
                          </span>
                        </TooltipTrigger>
                        <TooltipContent>
                          {t(`type.${domain.type}Hint`)}
                        </TooltipContent>
                      </Tooltip>
                      {domain.is_test ? (
                        <Badge
                          variant="outline"
                          className="font-normal text-muted-foreground"
                        >
                          {t("testDomain")}
                        </Badge>
                      ) : null}
                    </div>

                    {domain.type === "redirect" && domain.redirect_to ? (
                      <p className="flex items-center gap-1 text-xs text-muted-foreground">
                        <ArrowRight className="size-3" />
                        <span className="truncate font-mono">
                          {domain.redirect_to}
                        </span>
                        {domain.redirect_status ? (
                          <span>· {domain.redirect_status}</span>
                        ) : null}
                      </p>
                    ) : null}

                    <p
                      className={cn(
                        "flex items-center gap-1.5 text-xs",
                        domain.dns_verified
                          ? "text-success"
                          : "text-muted-foreground",
                      )}
                    >
                      {domain.dns_verified ? (
                        <CheckCircle2 className="size-3.5 shrink-0" />
                      ) : (
                        <CircleDashed className="size-3.5 shrink-0" />
                      )}
                      <span>
                        {domain.dns_verified
                          ? t("dns.verified")
                          : t("dns.unverified")}
                        {domain.dns_resolved_ip ? (
                          <span className="ml-1 font-mono text-muted-foreground">
                            ({domain.dns_resolved_ip})
                          </span>
                        ) : null}
                      </span>
                    </p>

                    {/* HTTPS status for this name. */}
                    {(() => {
                      const ssl = sslRowState(certificate, coverageOf(domain.domain));
                      if (!ssl) return null;
                      const SslIcon = ssl.icon;
                      return (
                        <p className={cn("flex items-center gap-1.5 text-xs", ssl.tone)}>
                          <SslIcon className="size-3.5 shrink-0" aria-hidden />
                          <span>{t(`sslRow.${ssl.key}`)}</span>
                        </p>
                      );
                    })()}

                    {/* Behind Cloudflare: a common support question. */}
                    {domain.behind_proxy ? (
                      <p className="flex items-start gap-1.5 text-xs text-warning">
                        <ShieldAlert className="mt-0.5 size-3.5 shrink-0" />
                        <span>{t("dns.behindProxy")}</span>
                      </p>
                    ) : null}

                    {/* Turn "not verified" into a next step: the A-record target.
                        Skipped for proxied names (own message above) and test
                        domains (nip.io resolves itself). */}
                    {!domain.dns_verified &&
                    !domain.behind_proxy &&
                    !domain.is_test ? (
                      serverIp ? (
                        <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                          <span>{t("dns.pointLabel")}</span>
                          <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-foreground">
                            {serverIp}
                          </code>
                          <CopyButton value={serverIp} className="size-6" />
                        </p>
                      ) : (
                        <p className="text-xs text-muted-foreground">
                          {t("dns.pointGeneric")}
                        </p>
                      )
                    ) : null}
                  </div>

                  <div className="flex shrink-0 items-center gap-1">
                    {/* Visit link (not for redirects); https only when the
                        certificate covers this name. The slot is always held
                        so the buttons after it line up across rows. */}
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
                    {/* Not behind `canManage`: the verify route is gated at
                        view level and changes nothing. */}
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={isVerifying}
                      onClick={() => onVerify(domain)}
                    >
                      <RotateCw className={isVerifying ? "size-3.5 animate-spin" : "size-3.5"} />
                      {t("dns.verify")}
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
                            {/* Not offered on the primary (the server refuses);
                                this menu is hidden there anyway. */}
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
          {/* The installer's syncUrl rewrites the stored address for these
              types (e.g. WordPress siteurl and home). No files are renamed. */}
          {promoteTarget && ADDRESS_SYNCED_TYPES.has(siteType) ? (
            <li>{t("promote.updatesAddress", { domain: promoteTarget.domain })}</li>
          ) : null}
        </ul>

        {/* The new primary is not on the certificate, so browsers will refuse
            it over HTTPS. */}
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
      >
        {/* certbot fails the whole renewal if any name in the lineage is
            unreachable, so removing a covered name breaks renewal for the rest. */}
        {deleteTarget && coverageOf(deleteTarget.domain) === "covered" ? (
          <Caution>
            {t("removeConfirm.onCertificate", { domain: deleteTarget.domain })}
          </Caution>
        ) : null}
      </ConfirmDialog>
    </Card>
  );
}
