"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
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
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
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

/**
 * Networks and volumes on this server, as two lists built like every other list in
 * the panel (Krishna, 21 Oct): a card head with its Create button, the shared table,
 * and a ⋯ menu per row whose Remove is red and says why when it cannot run.
 *
 * Every destructive control is either absent or confirmed, and the API refuses the
 * same cases independently.
 */
export function DockerResourcesPanel({
  initialNetworks,
  initialVolumes,
  sites = [],
  applicationNames = {},
  canManage,
  canManageSites = false,
}) {
  const t = useTranslations("docker");
  const { refreshAndWait } = useRefresh();

  const [removing, setRemoving] = useState(false);
  const [confirm, setConfirm] = useState(null);
  const [attaching, setAttaching] = useState(null);
  const [creating, setCreating] = useState(null);

  // Docker's own networks (bridge, host, none) can never be removed or joined, so
  // they fold into one locked line instead of three rows of "Docker's own".
  const builtIn = initialNetworks.filter((network) => network.built_in);
  const networks = initialNetworks.filter((network) => !network.built_in);

  const appName = (id) => applicationNames[id] ?? t("ownedByApp", { id });

  // Why a row's Remove cannot run, or null. The endpoint refuses a network a
  // stopped application names as well as one with containers on it.
  const networkRemoveReason = (network) =>
    !canManage
      ? t("noPermission")
      : network.containers.length > 0
        ? t("networks.removeBusy")
        : network.sites.length > 0
          ? t("networks.removeUsedBySites", { sites: network.sites.map((site) => site.name).join(", ") })
          : null;
  const volumeRemoveReason = (volume) =>
    !canManage
      ? t("noPermission")
      : volume.in_use
        ? t("volumes.removeBusy")
        : volume.sites.length > 0
          ? t("volumes.removeUsedBySites", { sites: volume.sites.map((site) => site.name).join(", ") })
          : null;
  // Another application's own network or volume is its private one.
  const attachReason = (resource) =>
    resource.application_id ? t("attach.ownedReason") : !canManageSites ? t("attach.noPermission") : null;

  async function remove() {
    if (!confirm) return;
    setRemoving(true);
    try {
      await (confirm.kind === "network" ? deleteDockerNetwork(confirm.name) : deleteDockerVolume(confirm.name));
      await refreshAndWait();
      toast.success(t(`${confirm.kind}s.removed`, { name: confirm.name }));
      setConfirm(null);
    } catch (error) {
      // The API names the containers on a busy network; say what it said.
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
    <ReasonTooltip reason={canManage ? null : t("noPermission")}>
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
      // The same share in both tables, so the second column starts at one line.
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
      cell: ({ row }) => <span className="font-mono text-xs tabular-nums">{row.original.size || "—"}</span>,
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
        title={t("networks.title")}
        count={networks.length}
        description={t("networks.hint")}
        action={networks.length > 0 ? createButton("network") : null}
        footer={
          // A label, not disabled rows: Docker recreates these on restart.
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
          <>
            <div className="hidden md:block">
              <DataTable bare roomy columns={networkColumns} data={networks} rowId={(network) => network.name} />
            </div>
            <CardList className="p-4 sm:grid-cols-1 md:hidden">
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
          </>
        )}
      </ListSection>

      <ListSection
        title={t("volumes.title")}
        count={initialVolumes.length}
        description={t("volumes.hint")}
        action={initialVolumes.length > 0 ? createButton("volume") : null}
      >
        {initialVolumes.length === 0 ? (
          <EmptyState icon={HardDrive} title={t("volumes.empty")} description={t("volumes.emptyBody")} action={createButton("volume")} />
        ) : (
          <>
            <div className="hidden md:block">
              <DataTable bare roomy columns={volumeColumns} data={initialVolumes} rowId={(volume) => volume.name} />
            </div>
            <CardList className="p-4 sm:grid-cols-1 md:hidden">
              {initialVolumes.map((volume) => (
                <CardListItem key={volume.name}>
                  <div className="flex items-start justify-between gap-3">
                    <VolumeName volume={volume} appName={appName} />
                    {rowMenu("volume", volume, volumeRemoveReason(volume))}
                  </div>
                  <CardFacts>
                    <CardFact label={t("columns.size")} value={volume.size || "—"} />
                    <CardFact label={t("columns.usedBy")}>
                      <VolumeUsage volume={volume} />
                    </CardFact>
                  </CardFacts>
                </CardListItem>
              ))}
            </CardList>
          </>
        )}
      </ListSection>

      <ConfirmDialog
        open={confirm !== null}
        onOpenChange={(open) => !open && !removing && setConfirm(null)}
        icon={Trash2}
        tone="destructive"
        title={confirm ? t(`${confirm.kind}s.removeTitle`, { name: confirm.name }) : ""}
        // A volume holds data, so the dialog answers "does this delete my database".
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
        // The menu already refuses these; this covers a row that went stale.
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
      />
    </div>
  );
}

/** The card head every list in the panel has: title, count and line; action right. */
function ListSection({ title, count, description, action, footer, children }) {
  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
        <div className="min-w-0">
          <h2 className="flex items-center gap-2 text-[15px] font-semibold tracking-tight">
            {title}
            <span className="text-xs font-normal tabular-nums text-muted-foreground">{count}</span>
          </h2>
          <p className="text-sm text-muted-foreground">{description}</p>
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
          <DropdownMenuSeparator />
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
      {/* An application's own network says whose; a shared one says who joined it.
          The delete refuses on those too, so the reason is visible before the click. */}
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
          {/* Published (reachable from the host) and exposed (this network only)
              look identical unless someone says so. */}
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
      {/* Where it is on the host, for rsync and support answers. Long and uniform, so
          it truncates from the left; <bdi> keeps the leading "/" where it belongs. */}
      {volume.mountpoint ? (
        <span className="mt-0.5 flex min-w-0 items-center gap-1">
          <code className="min-w-0 truncate font-mono text-xs text-muted-foreground" dir="rtl" title={volume.mountpoint}>
            <bdi dir="ltr">{volume.mountpoint}</bdi>
          </code>
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
      {/* Which application mounts it and where says what the volume IS; a stopped
          application still owns its data, so it is listed regardless. */}
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
      {/* Not in use, in use and named, in use but unnameable: three different facts. */}
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
      if (!handleValidationError(error, form)) toast.error(apiMessage(error, t("failed")));
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
              <FormMessage>{form.formState.errors.name?.type === "invalid_string" ? t("nameRule") : undefined}</FormMessage>
            </FormItem>
          )}
        />
      </FormModal>
    </Form>
  );
}
