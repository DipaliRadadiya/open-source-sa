"use client";

import { HeadIcon } from "@/components/ui/head-icon";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useRefresh } from "@/hooks/use-refresh";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { HardDrive, Link2, Loader2, Lock, MoreHorizontal, Network, Plus, Trash2 } from "lucide-react";
import {
  createDockerNetwork,
  createDockerVolume,
  deleteDockerNetwork,
  deleteDockerVolume,
} from "@/lib/api/docker";
import { dockerNameSchema } from "@/lib/schemas/docker";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { CopyButton } from "@/components/ui/copy-button";
import { DataTable } from "@/components/ui/data-table";
import { FormModal } from "@/components/ui/form-modal";
import { Input } from "@/components/ui/input";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { EmptyState } from "@/components/data-table/empty-state";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";
import { AttachSiteDialog } from "@/components/docker/attach-site-dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";
import { HoverHint } from "@/components/ui/hover-hint";
import { formatDockerSize } from "@/lib/format/bytes";

export function DockerResourcesPanel({
  initialNetworks,
  initialVolumes,
  sites = [],
  applicationNames = {},
  canManage,
  canManageSites = false,
}) {
  const t = useTranslations("docker");
  const format = useFormatter();
  const { refreshAndWait } = useRefresh();

  const [removing, setRemoving] = useState(false);
  const [confirm, setConfirm] = useState(null);
  const [attaching, setAttaching] = useState(null);
  const [creating, setCreating] = useState(null);

  const builtIn = initialNetworks.filter((network) => network.built_in);
  const networks = initialNetworks.filter((network) => !network.built_in);

  const appName = (id) => applicationNames[id] ?? t("ownedByApp", { id });

  const networkRemoveReason = (network) =>
    !canManage
      ? DENIED
      : network.containers.length > 0
        ? t("networks.removeBusy")
        : network.sites.length > 0
          ? t("networks.removeUsedBySites", { sites: network.sites.map((site) => site.name).join(", ") })
          : null;
  const volumeRemoveReason = (volume) =>
    !canManage
      ? DENIED
      : volume.in_use
        ? t("volumes.removeBusy")
        : volume.sites.length > 0
          ? t("volumes.removeUsedBySites", { sites: volume.sites.map((site) => site.name).join(", ") })
          : null;
  const attachReason = (resource) =>
    resource.application_id ? t("attach.ownedReason") : !canManageSites ? DENIED : null;

  async function remove() {
    if (!confirm) return;
    setRemoving(true);
    try {
      await (confirm.kind === "network" ? deleteDockerNetwork(confirm.name) : deleteDockerVolume(confirm.name));
      await refreshAndWait();
      toast.success(t(`${confirm.kind}s.removed`, { name: confirm.name }));
      setConfirm(null);
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setRemoving(false);
    }
  }

  const rowMenu = (kind, resource, removeReason) => (
    <RowMenu
      label={t(`${kind}s.actionsAria`, { name: resource.name })}
      attachReason={attachReason(resource)}
      removeReason={removeReason}
      onAttach={() => setAttaching({ kind, name: resource.name })}
      onRemove={() =>
        setConfirm({
          kind,
          name: resource.name,
          busy: kind === "network" ? resource.containers.length > 0 : resource.in_use,
          sites: resource.sites.map((site) => site.name),
        })
      }
    />
  );

  const createButton = (kind) => (
    <ReasonTooltip reason={canManage ? null : DENIED}>
      <Button disabled={!canManage} onClick={() => setCreating(kind)}>
        <Plus className="size-4" />
        {t(`${kind}s.createAction`)}
      </Button>
    </ReasonTooltip>
  );

  const networkColumns = [
    {
      id: "name",
      header: t("columns.name"),
      meta: { className: "w-[40%]" },
      cell: ({ row }) => <NetworkName network={row.original} appName={appName} />,
    },
    {
      id: "containers",
      header: t("columns.attached"),
      cell: ({ row }) => <Containers network={row.original} />,
    },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("columns.actions")}</span>,
      meta: { className: "w-12 text-right" },
      cell: ({ row }) => rowMenu("network", row.original, networkRemoveReason(row.original)),
    },
  ];

  const volumeColumns = [
    {
      id: "name",
      header: t("columns.name"),
      meta: { className: "w-[40%]" },
      cell: ({ row }) => <VolumeName volume={row.original} appName={appName} />,
    },
    {
      id: "size",
      header: () => <span className="block text-right">{t("columns.size")}</span>,
      meta: { className: "w-28 text-right" },
      cell: ({ row }) => <span className="font-mono text-xs tabular-nums">{formatDockerSize(row.original.size, format) || "—"}</span>,
    },
    {
      id: "usedBy",
      header: t("columns.usedBy"),
      cell: ({ row }) => <VolumeUsage volume={row.original} />,
    },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("columns.actions")}</span>,
      meta: { className: "w-12 text-right" },
      cell: ({ row }) => rowMenu("volume", row.original, volumeRemoveReason(row.original)),
    },
  ];

  return (
    <div className="space-y-6">
      <ListSection
        icon={Network}
        title={t("networks.title")}
        count={networks.length}
        description={t("networks.hint")}
        action={networks.length > 0 ? createButton("network") : null}
        footer={
          builtIn.length > 0 ? (
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <Lock className="size-3 shrink-0" />
              {t("networks.builtInLine", { names: builtIn.map((network) => network.name).join(", ") })}
            </p>
          ) : null
        }
      >
        {networks.length === 0 ? (
          <EmptyState icon={Network} title={t("networks.empty")} description={t("networks.emptyBody")} action={createButton("network")} />
        ) : (
          <div className="@container">
            <div className="hidden @min-[900px]/page:block">
              <DataTable bare roomy columns={networkColumns} data={networks} rowId={(network) => network.name} />
            </div>
            <CardList className="p-4 sm:grid-cols-1 @min-[900px]/page:hidden">
              {networks.map((network) => (
                <CardListItem key={network.name}>
                  <div className="flex items-start justify-between gap-3">
                    <NetworkName network={network} appName={appName} />
                    {rowMenu("network", network, networkRemoveReason(network))}
                  </div>
                  <CardFacts>
                    <CardFact label={t("columns.attached")}>
                      <Containers network={network} />
                    </CardFact>
                  </CardFacts>
                </CardListItem>
              ))}
            </CardList>
          </div>
        )}
      </ListSection>

      <ListSection
        icon={HardDrive}
        title={t("volumes.title")}
        count={initialVolumes.length}
        description={t("volumes.hint")}
        action={initialVolumes.length > 0 ? createButton("volume") : null}
      >
        {initialVolumes.length === 0 ? (
          <EmptyState icon={HardDrive} title={t("volumes.empty")} description={t("volumes.emptyBody")} action={createButton("volume")} />
        ) : (
          <div className="@container">
            <div className="hidden @min-[900px]/page:block">
              <DataTable bare roomy columns={volumeColumns} data={initialVolumes} rowId={(volume) => volume.name} />
            </div>
            <CardList className="p-4 sm:grid-cols-1 @min-[900px]/page:hidden">
              {initialVolumes.map((volume) => (
                <CardListItem key={volume.name}>
                  <div className="flex items-start justify-between gap-3">
                    <VolumeName volume={volume} appName={appName} />
                    {rowMenu("volume", volume, volumeRemoveReason(volume))}
                  </div>
                  <CardFacts>
                    <CardFact label={t("columns.size")} value={formatDockerSize(volume.size, format) || "—"} />
                    <CardFact label={t("columns.usedBy")}>
                      <VolumeUsage volume={volume} />
                    </CardFact>
                  </CardFacts>
                </CardListItem>
              ))}
            </CardList>
          </div>
        )}
      </ListSection>

      <ConfirmDialog
        open={confirm !== null}
        onOpenChange={(open) => !open && !removing && setConfirm(null)}
        icon={Trash2}
        tone="destructive"
        title={confirm ? t(`${confirm.kind}s.removeTitle`, { name: confirm.name }) : ""}
        description={
          confirm
            ? confirm.busy
              ? t(`${confirm.kind}s.removeBusy`)
              : confirm.sites?.length
                ? t(`${confirm.kind}s.removeUsedBySites`, { sites: confirm.sites.join(", ") })
                : t(`${confirm.kind}s.removeBody`)
            : ""
        }
        confirmLabel={t("remove")}
        confirmVariant="destructive"
        confirmDisabled={confirm?.busy === true || (confirm?.sites?.length ?? 0) > 0}
        pending={removing}
        onConfirm={remove}
      />

      {creating ? (
        <CreateDialog
          kind={creating}
          onOpenChange={(next) => !next && setCreating(null)}
          onCreated={async (name) => {
            await refreshAndWait();
            toast.success(t(`${creating}s.created`, { name }));
            setCreating(null);
          }}
        />
      ) : null}

      <AttachSiteDialog
        open={attaching !== null}
        onOpenChange={(open) => !open && setAttaching(null)}
        kind={attaching?.kind}
        name={attaching?.name ?? ""}
        sites={sites}
        networks={networks}
      />
    </div>
  );
}

function ListSection({ title, icon = null, count, description, action, footer, children }) {
  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
        <div className="flex min-w-0 items-start gap-3">
          <HeadIcon icon={icon} />
          <div className="min-w-0">
          <h2 className="flex items-center gap-2 text-[15px] font-semibold tracking-tight">
            {title}
            <span className="text-xs font-normal tabular-nums text-muted-foreground">{count}</span>
          </h2>
          <p className="text-sm text-muted-foreground">{description}</p>
          </div>
        </div>
        {action}
      </div>
      {children}
      {footer ? <div className="border-t px-5 py-3">{footer}</div> : null}
    </Card>
  );
}

function RowMenu({ label, attachReason, removeReason, onAttach, onRemove }) {
  const t = useTranslations("docker");
  if (attachReason === DENIED && removeReason === DENIED) return null;
  return (
    <div className="text-right">
      <DropdownMenu>
        <Tooltip>
          <TooltipTrigger asChild>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="size-8" aria-label={label}>
                <MoreHorizontal className="size-4" />
              </Button>
            </DropdownMenuTrigger>
          </TooltipTrigger>
          <TooltipContent>{label}</TooltipContent>
        </Tooltip>
        <DropdownMenuContent align="end" className="w-56">
          <MenuItemHint hint={attachReason}>
            <DropdownMenuItem disabled={Boolean(attachReason)} onSelect={onAttach}>
              <Link2 className="size-4" />
              {t("attach.action")}
            </DropdownMenuItem>
          </MenuItemHint>
          {attachReason === DENIED || removeReason === DENIED ? null : <DropdownMenuSeparator />}
          <MenuItemHint hint={removeReason}>
            <DropdownMenuItem variant="destructive" disabled={Boolean(removeReason)} onSelect={onRemove}>
              <Trash2 className="size-4" />
              {t("remove")}
            </DropdownMenuItem>
          </MenuItemHint>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}

function NetworkName({ network, appName }) {
  const t = useTranslations("docker");
  return (
    <div className="min-w-0">
      <p className="font-mono text-[13px] font-medium break-all">{network.name}</p>
      <p className="mt-0.5 text-xs text-muted-foreground">
        {network.application_id
          ? t("networks.ownedBy", { app: appName(network.application_id) })
          : network.sites.length > 0
            ? t("networks.joinedBy", { sites: network.sites.map((site) => site.name).join(", ") })
            : t("networks.shared")}
      </p>
    </div>
  );
}

function Containers({ network }) {
  const t = useTranslations("docker");
  if (network.containers.length === 0) return <span className="text-sm text-muted-foreground">{t("none")}</span>;
  return (
    <ul className="space-y-1.5">
      {network.containers.map((container) => (
        <li key={container.name} className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <span className="font-mono text-xs">{container.name}</span>
          {container.ports.length === 0 ? (
            <span className="text-xs text-muted-foreground">{t("networks.noPorts")}</span>
          ) : (
            container.ports.map((port) => (
              <span key={port} className="rounded-md border bg-muted/40 px-1.5 py-0.5 font-mono text-xs text-muted-foreground">
                {port}
              </span>
            ))
          )}
          {container.published ? null : (
            <span className="text-xs text-muted-foreground">{t("networks.internalOnly")}</span>
          )}
        </li>
      ))}
    </ul>
  );
}

function VolumeName({ volume, appName }) {
  const t = useTranslations("docker");
  return (
    <div className="min-w-0 max-w-96">
      <p className="flex flex-wrap items-baseline gap-x-2">
        <span className="font-mono text-[13px] font-medium break-all">{volume.name}</span>
        {volume.application_id ? (
          <span className="text-xs text-muted-foreground">{t("volumes.ownedBy", { app: appName(volume.application_id) })}</span>
        ) : null}
      </p>
      {volume.mountpoint ? (
        <span className="mt-0.5 flex min-w-0 items-center gap-1">
          <HoverHint content={volume.mountpoint}><code className="min-w-0 truncate font-mono text-xs text-muted-foreground" dir="rtl">
            <bdi dir="ltr">{volume.mountpoint}</bdi>
          </code></HoverHint>
          <CopyButton value={volume.mountpoint} label={t("volumes.copyPath")} className="size-6" />
        </span>
      ) : null}
    </div>
  );
}

function VolumeUsage({ volume }) {
  const t = useTranslations("docker");
  return (
    <div className="space-y-0.5 text-sm">
      {volume.sites.length > 0 ? (
        <ul className="space-y-0.5">
          {volume.sites.map((site) => (
            <li key={`${site.id}:${site.path}`}>
              <span className="font-medium">{site.name}</span>
              {site.path ? <span className="font-mono text-xs text-muted-foreground"> → {site.path}</span> : null}
            </li>
          ))}
        </ul>
      ) : null}
      {!volume.in_use ? (
        volume.sites.length === 0 ? <span className="text-muted-foreground">{t("volumes.unused")}</span> : null
      ) : volume.container_names.length > 0 ? (
        <p className="font-mono text-xs text-muted-foreground">{volume.container_names.join(", ")}</p>
      ) : (
        <p className="text-xs text-muted-foreground">{t("volumes.inUseUnnamed", { count: volume.containers })}</p>
      )}
    </div>
  );
}

function CreateDialog({ kind, onOpenChange, onCreated }) {
  const t = useTranslations("docker");
  const tc = useTranslations("common");
  const form = useForm({
    resolver: zodResolver(z.object({ name: dockerNameSchema })),
    defaultValues: { name: "" },
  });
  const submitting = form.formState.isSubmitting;

  async function onSubmit({ name }) {
    try {
      await (kind === "network" ? createDockerNetwork(name) : createDockerVolume(name));
      await onCreated(name);
    } catch (error) {
      handleValidationError(error, form, { fallback: t("failed") });
    }
  }

  return (
    <Form {...form}>
      <FormModal
        open
        onOpenChange={(next) => !submitting && onOpenChange(next)}
        asForm
        onSubmit={form.handleSubmit(onSubmit)}
        icon={kind === "network" ? Network : HardDrive}
        title={t(`${kind}s.createAction`)}
        description={t(`${kind}s.hint`)}
        footer={
          <>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              {tc("cancel")}
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4" />}
              {t(`${kind}s.createAction`)}
            </Button>
          </>
        }
      >
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("columns.name")}</FormLabel>
              <FormControl>
                <Input placeholder={t(`${kind}s.placeholder`)} autoComplete="off" spellCheck={false} className="font-mono text-sm" autoFocus {...field} />
              </FormControl>
              <FormDescription>{t("nameRule")}</FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
      </FormModal>
    </Form>
  );
}
