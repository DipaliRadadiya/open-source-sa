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

const TYPE_VARIANT = {
  primary: "default",
  alias: "secondary",
  redirect: "outline",
};

/**
 * Whether the site's certificate covers this exact name.
 *
 * `missing_domains` is the backend's own list of site names the certificate
 * does NOT carry, so this needs no wildcard matching of its own — and when the
 * list is empty for any reason the answer is "covered", which leaves every
 * existing link untouched rather than downgrading a whole panel to http.
 */
function coveredByCertificate(certificate, domain) {
  return !certificate?.missing_domains?.includes(domain);
}

export function DomainsSection({
  appId,
  domains = [],
  canManage = false,
  serverIp = null,
  secured = false,
  siteType = null,
  // What is securing this site right now, or null. Passed through to the Add
  // dialog rather than reduced to a boolean here: the advice for an uploaded
  // certificate is the opposite of the advice for a Let's Encrypt one, and
  // `secured` cannot tell them apart.
  certificate = null,
}) {
  const t = useTranslations("applications.domains");
  const router = useRouter();

  const [addOpen, setAddOpen] = useState(false);
  const [promoteTarget, setPromoteTarget] = useState(null);
  // Read from the list rather than carried on the menu item: promoting is the
  // one action whose consequence is about the name being replaced, not the one
  // being clicked.
  const currentPrimary = domains.find((domain) => domain.type === "primary");
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [pending, setPending] = useState(false);
  // Per-row spinner for the inline verify action.
  const [verifying, setVerifying] = useState({});

  /*
   * Whether an ACTIVE certificate lists this exact name.
   *
   * Both confirm dialogs below need it and neither had it. Only an active
   * certificate counts: a pending or failed one is securing nothing, so
   * warning about its coverage would be noise on top of a problem the SSL card
   * already reports.
   */
  const activeCertificate = certificate?.status === "active" ? certificate : null;
  const onCertificate = (domain) => Boolean(activeCertificate?.domains?.includes(domain));

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
    const target = deleteTarget.domain;
    setPending(true);
    try {
      await deleteDomain(appId, target);
      toast.success(t("toast.removed", { domain: target }));
      setDeleteTarget(null);
      router.refresh();
    } catch (error) {
      /*
       * Already gone — another tab, another person, or a click that landed
       * after all. The reader wanted this name off the application and it is
       * off; reporting a failure leaves the dialog open over a row about to
       * disappear and invites a retry that can only ever 404.
       */
      if (error?.response?.status === 404) {
        toast.info(t("toast.removedAlready", { domain: target }));
        setDeleteTarget(null);
        router.refresh();
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
          /* No border here. The rows already sit inside a Card, and a second
             frame around them drew the same edge twice — the coloured frames
             on the SSL card mean something, this one meant nothing. */
          <div className="-mx-6 -mb-6 divide-y overflow-hidden rounded-b-xl border-t">
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
                      {/* Name and its copy button are one unit. Loose in the
                          wrapping row they separated at phone width — the icon
                          dropped to the next line under a name it no longer
                          looked attached to, and inconsistently, because it
                          depended on the length of each name. */}
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
                    {/*
                      * Open the live site. Shown to everyone; redirects serve
                      * nothing.
                      *
                      * https only when the certificate covers THIS name, not
                      * merely when the site has one. `secured` is the site's
                      * overall SSL status, so a domain added after the
                      * certificate was issued got an https link straight into a
                      * browser warning — the SSL card immediately below already
                      * names that domain as uncovered.
                      *
                      * Keyed off `missing_domains` rather than the positive
                      * `domains` list on purpose: if the backend has not
                      * computed coverage, an empty `missing_domains` leaves
                      * every link exactly as it is today, whereas an empty
                      * `domains` would downgrade every site to http. It also
                      * avoids re-implementing wildcard matching here.
                      */}
                    {/* The slot is held whether or not the link renders.
                        Dropped entirely, every button after it shifted left,
                        so "Verify DNS" sat at a different x on each row and
                        the column read as ragged — a redirect row and a
                        verified row never lined up. */}
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
                                href={`${secured && coveredByCertificate(certificate, domain.domain) ? "https" : "http"}://${domain.domain}`}
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
                    {/*
                      * Not behind `canManage`. Re-checking DNS changes nothing
                      * on the server — the route is gated by `app_domain` at
                      * VIEW level, same as reading this page — and "is my DNS
                      * pointing here yet?" is the question a read-only holder
                      * most often has. It was the one control on the row that
                      * they could have used and could not see.
                      */}
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
        <AddDomainDialog
          appId={appId}
          open={addOpen}
          onOpenChange={setAddOpen}
          serverIp={serverIp}
          certificate={certificate}
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
      >
        {/* The swap is the fact worth seeing first, and the name being replaced
            is the one thing the title cannot show. Contrast alone separates the
            two — the outgoing name is muted, the incoming one is not — rather
            than stacking size, weight and colour on the same line. */}
        {currentPrimary ? (
          <dl className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-4 gap-y-1.5 rounded-lg border p-3">
            <dt className="text-xs text-muted-foreground">
              {t("promote.nowLabel")}
            </dt>
            <dd className="truncate font-mono text-sm text-muted-foreground">
              {currentPrimary.domain}
            </dd>
            <dt className="text-xs text-muted-foreground">
              {t("promote.afterLabel")}
            </dt>
            <dd className="truncate font-mono text-sm">
              {promoteTarget?.domain}
            </dd>
          </dl>
        ) : null}

        {/* One line per consequence. These were a single sentence joined by
            semicolons, which is exactly the shape nobody reads before clicking
            a confirm button. */}
        <ul className="list-disc space-y-1.5 pl-5 text-sm text-muted-foreground">
          {currentPrimary ? (
            <li>
              {t("promote.keepsServing", { domain: currentPrimary.domain })}
            </li>
          ) : null}
          <li>{t("promote.renamesFiles")}</li>
        </ul>

        {/* Only WordPress stores its own address, so only WordPress is warned.
            Shown to every site type, this line trained people to skip the
            dialog. */}
        {siteType === "wordpress" ? (
          <Caution>{t("promote.cmsWarning")}</Caution>
        ) : null}

        {/* The name about to become the application's canonical address is not
            on the certificate, so from the moment this is confirmed the
            address people are sent to answers on 443 with a certificate issued
            for somebody else — a browser refusal, not a downgrade. The SSL
            card says a name is uncovered; it cannot know it is about to become
            the main one. */}
        {promoteTarget && activeCertificate && !onCertificate(promoteTarget.domain) ? (
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
        pending={pending}
        onConfirm={confirmDelete}
      >
        {/* The consequence nothing on this screen mentioned.
            certbot validates every name in a certificate's lineage and fails
            the WHOLE renewal if any one of them cannot be reached — so
            removing a covered name quietly stops the certificate renewing for
            the names that are still fine. Nothing goes wrong until it expires,
            which is the worst possible moment to find out. The SSL card
            reports it afterwards as a stale domain; this says it beforehand,
            when it is still a choice. */}
        {deleteTarget && onCertificate(deleteTarget.domain) ? (
          <Caution>
            {t("removeConfirm.onCertificate", { domain: deleteTarget.domain })}
          </Caution>
        ) : null}
      </ConfirmDialog>
    </Card>
  );
}
