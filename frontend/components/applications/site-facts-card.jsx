"use client";

import { useEffect, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import { CalendarClock, FileCode, FolderTree, HardDrive, Hexagon, KeyRound, Package, Loader2, Pencil, Plug, MoreHorizontal, Ruler, ScanSearch, Tags, User, Info } from "lucide-react";
import { detectApplicationSiteType, measureApplicationSize } from "@/lib/api/applications";
import { getSystemUserDetail } from "@/lib/api/system-users";
import {
  canDetectSiteType,
  narrowingTargets,
  siteTypeDetectionState,
  suggestedSiteType,
} from "@/lib/applications/site-type-detection";
import { SiteTypeRelabelDialog } from "@/components/applications/site-type-relabel-dialog";
import { apiMessage } from "@/lib/api/error-message";
import { formatBytes } from "@/lib/format/bytes";
import { parseApiDate } from "@/lib/format/api-date";
import { NEW_APP_MS, SETUP_WINDOW_MS, sizeMeasuredDuringSetup } from "@/lib/applications/size-measurement";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { CopyButton } from "@/components/ui/copy-button";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { WebRootDialog } from "@/components/applications/web-root-dialog";
import { NodeVersionDialog } from "@/components/applications/node-version-dialog";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { HoverHint } from "@/components/ui/hover-hint";

// Full class names for Tailwind.
const FACT_COLUMNS = { 3: "xl:grid-cols-3", 4: "xl:grid-cols-4" };

function factColumns(count) {
  if (count % 4 === 0) return FACT_COLUMNS[4];
  if (count % 3 === 0) return FACT_COLUMNS[3];
  return FACT_COLUMNS[4];
}

function Fact({ icon: Icon, label, value, mono, copy, copyValue = value, extraCopy = null, onEdit, editLabel, action, note, noteTone, menu, menuLabel, menuBusy = false, plain = false, strip = false, wide = false }) {
  const noteNode = note ? (
    <p className={`mt-0.5 text-xs leading-snug ${noteTone === "warning" ? "text-[color-mix(in_oklch,var(--warning)_75%,var(--foreground))] dark:text-warning" : "text-muted-foreground"}`}>
      {note}
    </p>
  ) : null;
  const controls = (
    <>
      {copy ? <CopyButton value={String(copyValue)} label={extraCopy?.firstLabel} outlined className={`border border-border/80 bg-card shadow-xs ${strip ? "pointer-coarse:hidden" : ""}`} /> : null}
      {extraCopy?.value ? (
        <CopyButton value={extraCopy.value} label={extraCopy.label} icon={extraCopy.icon} outlined className="border border-border/80 bg-card shadow-xs" />
      ) : extraCopy?.reason ? (
        <Button type="button" variant="outline" size="icon-sm" disabled disabledReason={extraCopy.reason} aria-label={extraCopy.label} className="size-7 shrink-0">
          <extraCopy.icon className="size-3.5" />
        </Button>
      ) : null}
      {menu?.length ? (
        <DropdownMenu>
          <HoverHint content={menuLabel}><DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="icon-sm"
              disabled={menuBusy || action?.busy}
              aria-label={menuLabel}
              className="size-7 shrink-0"
            >
              {menuBusy || action?.busy ? (
                <Loader2 className="size-3.5 animate-spin" />
              ) : (
                <MoreHorizontal className="size-3.5" />
              )}
            </Button>
          </DropdownMenuTrigger></HoverHint>
          <DropdownMenuContent align="end">
            {menu.map((item) => (
              <DropdownMenuItem key={item.key} onSelect={item.onSelect}>
                <item.icon className="size-3.5" />
                {item.label}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      ) : null}

      {action ? (
        action.text ? (
          <HoverHint content={action.label}><Button
            type="button"
            variant="outline"
            size="sm"
            onClick={action.onClick}
            disabled={action.busy}
            className="h-7 shrink-0"
          >
            {action.busy ? (
              <Loader2 className="size-3.5 animate-spin" />
            ) : (
              <action.icon className="size-3.5" />
            )}
            {action.text}
          </Button></HoverHint>
        ) : (
          <HoverHint content={action.label}><Button
            type="button"
            variant="outline"
            size="icon-sm"
            onClick={action.onClick}
            disabled={action.busy}
            aria-label={action.label}
            className="size-7 shrink-0"
          >
            {action.busy ? (
              <Loader2 className="size-3.5 animate-spin" />
            ) : (
              <action.icon className="size-3.5" />
            )}
          </Button></HoverHint>
        )
      ) : null}
      {onEdit ? (
        <Button
          type="button"
          variant="outline"
          size="icon-sm"
          onClick={onEdit}
          aria-label={editLabel}
          className="size-7 shrink-0"
        >
          <Pencil className="size-3.5" />
        </Button>
      ) : null}
    </>
  );

  if (strip) {
    return (
      <div className={`group/fact min-w-0 px-5 py-3 shadow-[inset_-1px_0_0_var(--border),inset_0_-1px_0_var(--border)] ${wide ? "col-span-2 xl:col-span-1" : ""}`}>
        <dt className="font-mono text-xs font-medium tracking-[0.1em] text-muted-foreground uppercase">{label}</dt>
        <dd className="flex min-h-7 min-w-0 items-center gap-2">
          <HoverHint content={typeof value === "string" ? value : undefined}><span
            className={`min-w-0 truncate text-sm font-medium ${mono ? "font-mono text-[13px] tabular-nums" : ""}`}
          >
            {value}
          </span></HoverHint>
          <span className="flex items-center gap-1.5 transition-opacity duration-150 pointer-fine:opacity-0 pointer-fine:group-hover/fact:opacity-100 pointer-fine:group-focus-within/fact:opacity-100 pointer-fine:has-[[aria-expanded=true]]:opacity-100 pointer-fine:has-[.animate-spin]:opacity-100">
            {controls}
          </span>
        </dd>
        {noteNode ? <dd>{noteNode}</dd> : null}
      </div>
    );
  }

  if (plain) {
    return (
      <div className="min-w-0 py-2.5">
        <div className="flex min-w-0 items-center gap-3">
          <Icon className="size-4 shrink-0 text-muted-foreground" />
          <span className="shrink-0 text-sm text-muted-foreground">{label}</span>
          <HoverHint content={typeof value === "string" ? value : undefined}><span
            className={`min-w-0 flex-1 truncate text-right text-sm font-medium ${mono ? "font-mono text-[13px] tabular-nums" : ""}`}
          >
            {value}
          </span></HoverHint>
          {controls}
        </div>
        {noteNode ? <div className="ps-7">{noteNode}</div> : null}
      </div>
    );
  }

  return (
    // min-w-0: a grid item defaults to min-width:auto, so truncate never fires.
    <div className="flex min-w-0 items-center gap-2.5 rounded-lg border bg-muted/30 px-3 py-2.5">
      <Icon className="size-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0 flex-1">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p
          className={`truncate text-sm font-medium ${mono ? "font-mono text-[13px] tabular-nums" : ""}`}
        >
          {value}
        </p>
        {noteNode}
      </div>
      {controls}
    </div>
  );
}

export function SiteFactsCard({ application, canManage = false, canManageSystemUsers = false, siteTypes = [], nodeVersions = [], nodeVersionsFailed = false, columns = null, list = false, strip = false, className }) {
  const t = useTranslations("applications");
  const format = useFormatter();
  const [editingWebRoot, setEditingWebRoot] = useState(false);
  const [editingNode, setEditingNode] = useState(false);
  const nodeChange = application.node_version_change ?? null;
  const nodeSwitching = nodeChange?.status === "switching";
  const nodeRange = siteTypes.find((type) => type.name === application.site_type)?.node_version_range ?? null;
  const [measuring, setMeasuring] = useState(false);
  const [ownerPassword, setOwnerPassword] = useState(undefined);
  const ownerId = application.system_user?.id ?? null;
  useEffect(() => {
    if (!canManageSystemUsers || !ownerId) return undefined;
    let live = true;
    getSystemUserDetail(ownerId)
      .then((res) => live && setOwnerPassword(res.data?.system_user?.password ?? null))
      .catch(() => {});
    return () => {
      live = false;
    };
  }, [canManageSystemUsers, ownerId]);
  const [detecting, setDetecting] = useState(false);
  const [refreshing, startRefresh] = useTransition();
  const probing = detecting || refreshing;
  const [relabelTo, setRelabelTo] = useState(null);
  const router = useRouter();
  const { refreshAndWait } = useRefresh();

  // On click only: files often arrive later, so an automatic probe records nothing found too early.
  async function detect() {
    setDetecting(true);
    try {
      const { data } = await detectApplicationSiteType(application.id);

      const found = data?.site_type_detection;
      const type = found?.detected_title ?? found?.detected;
      if (found?.suggested) {
        toast.success(t("siteTypeDetection.detectedSuggestion", { type }));
      } else if (type) {
        toast.success(
          found.matched
            ? t("siteTypeDetection.detectedFile", { type, file: found.matched })
            : t("siteTypeDetection.detected", { type }),
        );
      } else {
        toast.info(t("siteTypeDetection.detectedNothing"));
      }

      // router.refresh() can't be awaited, so a transition tracks it.
      startRefresh(() => router.refresh());
    } catch (err) {
      toast.error(apiMessage(err, t("siteTypeDetection.detectFailed")));
    } finally {
      setDetecting(false);
    }
  }

  const detection = application.site_type_detection;
  const detectionState = siteTypeDetectionState(application);
  const suggestion = suggestedSiteType(application);

  const typeNote = (() => {
    if (detectionState === "suggested") {
      return detection?.matched
        ? t("siteTypeDetection.looksLikeBecause", {
            type: detection.detected_title ?? suggestion,
            file: detection.matched,
          })
        : t("siteTypeDetection.looksLike", {
            type: detection.detected_title ?? suggestion,
          });
    }
    if (detectionState === "recognised") {
      const type = detection?.detected_title ?? detection?.detected;
      return detection?.matched
        ? t("siteTypeDetection.checkedFoundFile", { type, file: detection.matched })
        : t("siteTypeDetection.checkedFound", { type });
    }
    if (detectionState === "found") return t("siteTypeDetection.nothingFound");
    return null;
  })();

  const typeMenu = (() => {
    if (!canManage || !canDetectSiteType(application)) return [];

    const items = [
      {
        key: "detect",
        icon: ScanSearch,
        label: t("siteTypeDetection.detectAction"),
        onSelect: detect,
      },
    ];

    for (const name of narrowingTargets(application)) {
      const title = siteTypes.find((type) => type.name === name)?.title;
      if (!title) continue;
      items.push({
        key: `to-${name}`,
        icon: Tags,
        label: t("siteTypeDetection.changeTo", { type: title }),
        onSelect: () => setRelabelTo(name),
      });
    }

    return items;
  })();

  const relabelTitle =
    relabelTo === detection?.detected
      ? (detection?.detected_title ?? relabelTo)
      : (siteTypes.find((type) => type.name === relabelTo)?.title ?? relabelTo);

  async function measure() {
    setMeasuring(true);
    try {
      await measureApplicationSize(application.id);
      await refreshAndWait();
      toast.success(t("size.measured"));
    } catch (error) {
      toast.error(apiMessage(error, t("size.measureFailed")));
    } finally {
      setMeasuring(false);
    }
  }

  const measuredDuringSetup = sizeMeasuredDuringSetup(application);
  const createdAt = parseApiDate(application.created_at);
  const needsFirstSize = measuredDuringSetup || application.directory_size_bytes == null;
  useEffect(() => {
    if (!needsFirstSize || !createdAt || !canManage || application.status !== "active") return undefined;
    if (!measuredDuringSetup && Date.now() - createdAt.getTime() > NEW_APP_MS) return undefined;
    const wait = Math.max(0, createdAt.getTime() + SETUP_WINDOW_MS - Date.now());
    const timer = setTimeout(async () => {
      setMeasuring(true);
      try {
        await measureApplicationSize(application.id);
        await refreshAndWait();
      } catch {
        // the nightly sweep measures it anyway
      } finally {
        setMeasuring(false);
      }
    }, wait);
    return () => clearTimeout(timer);
    // Once per stored measurement.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [application.id, application.directory_size_measured_at, application.status, canManage]);

  const size = measuredDuringSetup ? null : formatBytes(application.directory_size_bytes, format);

  const volumeSize =
    application.volume_size_bytes === null ||
    application.volume_size_bytes === undefined
      ? null
      : formatBytes(application.volume_size_bytes, format);

  const container = application.serving_profile === "docker";

  const origin = application.origin;
  const originValue = origin?.kind === "recipe" && origin.recipe
    ? t("origin.factRecipe", { title: origin.recipe.title, version: origin.recipe.version })
    : origin?.kind === "image"
      ? origin.image || "—"
      : origin?.kind === "compose"
        ? t("origin.compose")
        : null;

  const facts = [
    {
      icon: Package,
      label: t("origin.factLabel"),
      value: originValue,
      mono: origin?.kind === "image",
      copy: origin?.kind === "image" && Boolean(origin.image),
      wide: container && strip,
    },
    {
      icon: Package,
      label: t("columns.type"),
      value: container ? null : application.site_type_title ?? application.site_type,
      note: typeNote,
      action:
        canManage && suggestion
          ? {
              onClick: () => setRelabelTo(suggestion),
              busy: probing,
              label: t("siteTypeDetection.reviewHint"),
              text: t("siteTypeDetection.reviewAction"),
              icon: ScanSearch,
            }
          : null,
      menuLabel: t("siteTypeDetection.menuHint"),
      menu: typeMenu,
      menuBusy: probing,
    },
    {
      icon: User,
      label: t("columns.owner"),
      value: application.system_user?.username,
      mono: true,
      copy: true,
      extraCopy: canManageSystemUsers && ownerPassword !== undefined
        ? {
            value: ownerPassword,
            label: t("facts.copyPassword"),
            firstLabel: t("facts.copyUsername"),
            icon: KeyRound,
            reason: ownerPassword ? null : t("facts.noPasswordSaved"),
          }
        : { firstLabel: t("facts.copyUsername") },
    },
    {
      icon: FolderTree,
      label: t("facts.webRoot"),
      value: container ? null : application.web_root,
      mono: true,
      copy: Boolean(application.document_root),
      copyValue: application.document_root,
      onEdit: canManage ? () => setEditingWebRoot(true) : null,
    },
    {
      icon: FileCode,
      label: t("facts.php"),
      value: list ? null : !application.serving_profile || application.serving_profile === "php" ? application.php_version : null,
      mono: true,
    },
    {
      icon: Hexagon,
      label: t("facts.node"),
      value: application.node_version,
      mono: true,
      note: nodeSwitching
        ? t("nodeVersion.switching", { target: nodeChange.target ?? "" })
        : nodeChange?.status === "failed"
          ? nodeChange.message
          : null,
      noteTone: nodeChange?.status === "failed" ? "warning" : null,
      action: nodeSwitching ? { busy: true, label: t("nodeVersion.switching", { target: nodeChange.target ?? "" }), icon: Hexagon } : null,
      onEdit: canManage && !nodeSwitching ? () => setEditingNode(true) : null,
      editLabel: t("nodeVersion.title"),
    },
    application.container_port
      ? {
          icon: Plug,
          label: t("facts.containerPort"),
          value: application.container_port,
          mono: true,
          copy: true,
          note: application.app_port ? t("facts.proxyPort", { port: application.app_port }) : null,
        }
      : { icon: Plug, label: t("facts.port"), value: application.app_port, mono: true, copy: true },
    {
      icon: HardDrive,
      label: t("columns.size"),
      value: size ?? (needsFirstSize && measuring ? t("size.measuring") : t("size.notMeasured")),
      note: size && volumeSize ? t("size.inVolumes", { size: volumeSize }) : null,
      action: canManage
        ? { onClick: measure, busy: measuring, label: t("size.measureHint"), icon: Ruler }
        : null,
    },
    (columns === 2 || list) && !strip ? null : { icon: CalendarClock, label: t("columns.created"), value: application.created_at_human },
  ].filter((fact) => fact && fact.value !== null && fact.value !== undefined && fact.value !== "");

  const dialogs = (
    <>
      {nodeSwitching ? <AutoRefresh intervalMs={3000} stopAfterMs={600000} /> : null}

      {application.node_version ? (
        <NodeVersionDialog
          application={application}
          range={nodeRange}
          versions={nodeVersions}
          versionsFailed={nodeVersionsFailed}
          open={editingNode}
          onOpenChange={setEditingNode}
        />
      ) : null}

      <WebRootDialog
        application={application}
        open={editingWebRoot}
        onOpenChange={setEditingWebRoot}
      />

      <SiteTypeRelabelDialog
        open={Boolean(relabelTo)}
        onOpenChange={(next) => setRelabelTo(next ? relabelTo : null)}
        application={application}
        target={relabelTo}
        targetTitle={relabelTitle}
        siteTypes={siteTypes}
        matched={relabelTo === suggestion ? detection?.matched : null}
      />
    </>
  );

  if (strip) {
    const cells = facts.reduce((count, fact) => count + (fact.wide ? 2 : 1), 0);
    return (
      <>
        <dl className={`-mr-px -mb-px grid grid-cols-2 sm:grid-cols-3 ${(cells === 6 && facts.some((fact) => fact.wide)) || facts.length <= 6 ? "xl:grid-cols-none xl:grid-flow-col xl:auto-cols-[minmax(0,auto)]" : "xl:grid-cols-4"} ${className ?? ""}`}>
          {facts.map((fact) => (
            <Fact key={fact.label} editLabel={t("webRoot.title")} strip {...fact} />
          ))}
        </dl>
        {dialogs}
      </>
    );
  }

  return (
    <Card className={className}>
      <CardHeader>
        <CardTitle icon={Info} as="h2" className="flex items-center gap-2">
          {t("facts.title")}
        </CardTitle>
        <CardDescription>{t("facts.description")}</CardDescription>
      </CardHeader>
      <CardContent
        className={
          list
            ? "divide-y divide-border/70 py-0"
            : `grid gap-2 sm:grid-cols-2 ${columns === 2 ? "sm:[&>*:last-child:nth-child(odd)]:col-span-2" : factColumns(facts.length)}`
        }
      >
        {facts.map((fact) => (
          <Fact key={fact.label} editLabel={t("webRoot.title")} plain={list} {...fact} />
        ))}
      </CardContent>

      {dialogs}
    </Card>
  );
}
