"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  Globe2,
  Plus,
  MoreHorizontal,
  RotateCw,
  Star,
  Trash2,
  ShieldAlert,
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

const TYPE_VARIANT = {
  primary: "default",
  alias: "secondary",
  redirect: "outline",
};

export function DomainsSection({
  appId,
  domains = [],
  canManage = false,
  serverIp = null,
  secured = false,
}) {
  const t = useTranslations("applications.domains");
  const router = useRouter();

  const [addOpen, setAddOpen] = useState(false);
  const [promoteTarget, setPromoteTarget] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [pending, setPending] = useState(false);
  // Per-row spinner for the inline verify action.
  const [verifying, setVerifying] = useState({});

  // Same plain button in the header and the empty-state, exactly like the
  // databases list — one definition so they can't drift.
  const addButton = canManage ? (
    <Button onClick={() => setAddOpen(true)}>
      <Plus className="size-4" />
      {t("add.action")}
    </Button>
  ) : null;

  async function onVerify(domain) {
    setVerifying((v) => ({ ...v, [domain.domain]: true }));
    try {
      // Confirm the outcome — a re-check that leaves the row unchanged (DNS
      // hasn't propagated) otherwise looks like nothing happened.
      const result = await verifyDomain(appId, domain.domain);
      if (result?.dns_verified) {
        toast.success(t("toast.verified", { domain: domain.domain }));
      } else {
        toast.info(t("toast.notPointing", { domain: domain.domain }));
      }
      router.refresh();
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
      toast.success(t("toast.promoted", { domain: promoteTarget.domain }));
      setPromoteTarget(null);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("toast.promoteFailed")));
    } finally {
      setPending(false);
    }
  }

  async function confirmDelete() {
    setPending(true);
    try {
      await deleteDomain(appId, deleteTarget.domain);
      toast.success(t("toast.removed", { domain: deleteTarget.domain }));
      setDeleteTarget(null);
      router.refresh();
    } catch (error) {
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
          <div className="divide-y rounded-xl border">
            {domains.map((domain) => {
              const isPrimary = domain.type === "primary";
              const isVerifying = Boolean(verifying[domain.domain]);
              return (
                <div key={domain.id} className="flex flex-wrap items-start gap-3 p-4">
                  <Globe2
                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden
                  />

                  <div className="min-w-40 flex-1 space-y-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="truncate font-mono text-sm">
                        {domain.domain}
                      </span>
                      <CopyButton
                        value={domain.domain}
                        label={t("copyDomain")}
                        className="size-6"
                      />
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

                    {/* Redirect target. */}
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

                    {/* DNS status. */}
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

                    {/* Behind Cloudflare — its own message, the #1 support question. */}
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
                    {/* Open the live site — https when a cert is active, else
                        http. Shown to everyone; redirects serve nothing. */}
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
                              href={`${secured ? "https" : "http"}://${domain.domain}`}
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
                    {canManage ? (
                      <>
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={isVerifying}
                          onClick={() => onVerify(domain)}
                        >
                          <RotateCw
                            className={
                              isVerifying ? "size-3.5 animate-spin" : "size-3.5"
                            }
                          />
                          {t("dns.verify")}
                        </Button>
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
                            {domain.type === "alias" ? (
                              <DropdownMenuItem
                                onSelect={() => setPromoteTarget(domain)}
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
                      </>
                    ) : null}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </CardContent>

      {canManage ? (
        <AddDomainDialog
          appId={appId}
          open={addOpen}
          onOpenChange={setAddOpen}
          serverIp={serverIp}
        />
      ) : null}

      <ConfirmDialog
        open={Boolean(promoteTarget)}
        onOpenChange={(o) => !o && setPromoteTarget(null)}
        icon={Star}
        title={t("promote.title", { domain: promoteTarget?.domain ?? "" })}
        description={t("promote.body")}
        cancelLabel={t("cancel")}
        confirmLabel={t("makePrimary")}
        pending={pending}
        onConfirm={confirmPromote}
      />

      <ConfirmDialog
        open={Boolean(deleteTarget)}
        onOpenChange={(o) => !o && setDeleteTarget(null)}
        icon={Trash2}
        tone="destructive"
        title={t("removeConfirm.title", { domain: deleteTarget?.domain ?? "" })}
        description={t("removeConfirm.body")}
        cancelLabel={t("cancel")}
        confirmLabel={t("remove")}
        pending={pending}
        onConfirm={confirmDelete}
      />
    </Card>
  );
}
