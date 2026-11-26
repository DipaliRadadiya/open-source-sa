"use client";

import { useRef, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Globe2, Plus, MoreHorizontal, RotateCw, Pencil, Star, Trash2, ShieldAlert, ShieldCheck, ShieldOff, CheckCircle2, CircleDashed, ArrowRight, ExternalLink, Globe } from "lucide-react";
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
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Caution } from "@/components/ui/caution";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/data-table/empty-state";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { CopyButton } from "@/components/ui/copy-button";
import { WrappedDomain } from "@/components/ui/domain-text";
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
import { FACT_LABEL } from "@/lib/theme/facts";

const ADDRESS_SYNCED_TYPES = new Set([
  "wordpress", "akaunting", "craftcms", "mautic", "moodle",
  "n8n", "nextcloud", "nodebb", "prestashop", "statamic",
]);

const TYPE_VARIANT = {
  primary: "default",
  alias: "secondary",
  redirect: "outline",
};

function certificateCoverage(certificate, domain) {
  if (certificate?.status !== "active") return "unknown";
  if (certificate.domains?.length) {
    const name = String(domain ?? "").toLowerCase();
    const covered = certificate.domains.some((entry) => {
      const on = String(entry).toLowerCase();
      if (on === name) return true;
      return on.startsWith("*.") && name.endsWith(on.slice(1)) && !name.slice(0, -on.length + 1).includes(".");
    });
    return covered ? "covered" : "uncovered";
  }
  if (certificate.missing_domains?.length) {
    return certificate.missing_domains.includes(domain) ? "uncovered" : "covered";
  }
  return "unknown";
}

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

const ROW_GRID = "md:grid-cols-[minmax(0,1fr)_10rem_10rem_13rem] md:items-start";

export function DomainsSection({
  appId,
  domains = [],
  canManage = false,
  serverIp = null,
  secured = false,
  siteType = null,
  certificate = null,
}) {
  const t = useTranslations("applications.domains");
  const { pending: refreshing, refreshThen, refreshAndWait } = useRefresh();

  const [addOpen, setAddOpen] = useState(false);
  const [promoteTarget, setPromoteTarget] = useState(null);
  const currentPrimary = domains.find((domain) => domain.type === "primary");
  const [editTarget, setEditTarget] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [pending, setPending] = useState(false);
  const [verifying, setVerifying] = useState({});
  const removed = useRef(false);

  const coverageOf = (domain) => certificateCoverage(certificate, domain);

  const addButton = canManage ? (
    <Button onClick={() => setAddOpen(true)} data-domains-add>
      <Plus className="size-4" />
      {t("add.action")}
    </Button>
  ) : null;

  async function onVerify(domain) {
    setVerifying((v) => ({ ...v, [domain.domain]: true }));
    try {
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
      <CardHeader className="flex flex-col gap-3 space-y-0 sm:flex-row sm:items-center sm:justify-between">
        <CardTitle icon={Globe}>
          {t("sectionTitle")}
        </CardTitle>
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
          <div className="-mx-(--card-spacing) -mb-(--card-spacing) border-t">
            <div
              aria-hidden
              data-slot="table-head"
              className={cn(ROW_GRID, "hidden h-11 gap-x-4 border-b bg-muted/40 px-5 text-muted-foreground md:grid md:items-center")}
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
                <div
                  key={domain.id}
                  className={cn("grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-2.5 px-4 py-3.5 md:gap-x-4 md:px-5", ROW_GRID)}
                >
                  <div className="col-span-2 flex min-w-0 items-center gap-3 md:col-span-1">
                    <span
                      className="hidden size-8 shrink-0 items-center justify-center rounded-[2px] border bg-muted/40 text-muted-foreground md:flex"
                      aria-hidden
                    >
                      <Globe2 className="size-4" />
                    </span>
                    <div className="min-w-0 flex-1 space-y-1">
                      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="min-w-0 font-mono text-sm">
                          <WrappedDomain domain={domain.domain} />
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

                      {domain.behind_proxy ? (
                        <p className="flex items-start gap-1.5 text-xs text-warning">
                          <ShieldAlert className="mt-0.5 size-3.5 shrink-0" />
                          <span>{t("dns.behindProxy")}</span>
                        </p>
                      ) : null}

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
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={isVerifying}
                      onClick={() => onVerify(domain)}
                    >
                      <RotateCw className={isVerifying ? "size-3.5 animate-spin" : "size-3.5"} />
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
        {promoteTarget?.from ? (
          <dl className="grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-4 gap-y-1.5 rounded-lg border p-3">
            <dt className={FACT_LABEL}>
              {t("promote.nowLabel")}
            </dt>
            <dd className="font-mono text-sm break-all text-muted-foreground">
              {promoteTarget.from}
            </dd>
            <dt className={FACT_LABEL}>
              {t("promote.afterLabel")}
            </dt>
            <dd className="font-mono text-sm break-all">
              {promoteTarget?.domain}
            </dd>
          </dl>
        ) : null}

        <ul className="list-disc space-y-1.5 pl-5 text-sm text-muted-foreground">
          {promoteTarget?.from ? (
            <li>
              {t("promote.keepsServing", { domain: promoteTarget.from })}
            </li>
          ) : null}
          {promoteTarget && ADDRESS_SYNCED_TYPES.has(siteType) ? (
            <li>{t("promote.updatesAddress", { domain: promoteTarget.domain })}</li>
          ) : null}
        </ul>

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
        {deleteTarget && certificate?.renewable && coverageOf(deleteTarget.domain) === "covered" ? (
          <Caution>
            {t("removeConfirm.onCertificate", { domain: deleteTarget.domain })}
          </Caution>
        ) : null}
      </ConfirmDialog>
    </Card>
  );
}
