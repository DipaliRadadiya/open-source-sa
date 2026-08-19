"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import {
  CalendarClock,
  FileCode,
  FolderTree,
  HardDrive,
  Hexagon,
  Info,
  Package,
  Loader2,
  Pencil,
  Plug,
  Ruler,
  User,
} from "lucide-react";
import { measureApplicationSize } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { formatBytes } from "@/lib/format/bytes";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { CopyButton } from "@/components/ui/copy-button";
import { Button } from "@/components/ui/button";
import { WebRootDialog } from "@/components/applications/web-root-dialog";

/**
 * What this site is and where it lives.
 *
 * Tiles rather than rows, matching the server dashboard's identity band: icon,
 * a small uppercase label, then the value on a tinted surface. Four flat white
 * cards of plain text is the "identical feature card" tell — the page reads as
 * boilerplate because nothing on it has any texture or weight. The tiles also
 * give the panel one visual language across its two dashboards instead of two.
 *
 * Every value is a fact somebody might have to quote, so paths and versions are
 * monospaced and the ones people paste carry a copy control. A fact that does
 * not apply to this site type is left out rather than shown as "—".
 */
/*
 * Tailwind needs the full class string at build time, so the column counts are
 * a lookup rather than a template. Only 3 and 4 are offered: the fact count
 * varies by site type — 6 for WordPress and Git sites, 8 when a Node runtime
 * adds a version and a port — and picking whichever divides evenly is what
 * keeps the last row full. Six tiles in a 4-column grid left half a row empty,
 * which reads as a card that failed to finish loading.
 */
const FACT_COLUMNS = { 3: "xl:grid-cols-3", 4: "xl:grid-cols-4" };

function factColumns(count) {
  if (count % 4 === 0) return FACT_COLUMNS[4];
  if (count % 3 === 0) return FACT_COLUMNS[3];
  return FACT_COLUMNS[4];
}

function Fact({ icon: Icon, label, value, mono, copy, onEdit, editLabel, action }) {
  return (
    // min-w-0: a grid item keeps min-width:auto, so without it the tile grows to
    // its longest word and `truncate` never fires.
    <div className="flex min-w-0 items-center gap-2.5 rounded-lg border bg-muted/30 px-3 py-2.5">
      <Icon className="size-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0 flex-1">
        <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
        <p
          className={`truncate text-sm font-medium ${mono ? "font-mono text-[13px] tabular-nums" : ""}`}
        >
          {value}
        </p>
      </div>
      {copy ? <CopyButton value={String(value)} /> : null}
      {action ? (
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          onClick={action.onClick}
          disabled={action.busy}
          aria-label={action.label}
          title={action.label}
          className="shrink-0"
        >
          {action.busy ? (
            <Loader2 className="size-3.5 animate-spin" />
          ) : (
            <Ruler className="size-3.5" />
          )}
        </Button>
      ) : null}
      {onEdit ? (
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          onClick={onEdit}
          aria-label={editLabel}
          className="shrink-0"
        >
          <Pencil className="size-3.5" />
        </Button>
      ) : null}
    </div>
  );
}

export function SiteFactsCard({ application, canManage = false, className }) {
  const t = useTranslations("applications");
  const format = useFormatter();
  const [editingWebRoot, setEditingWebRoot] = useState(false);
  const [measuring, setMeasuring] = useState(false);
  const router = useRouter();

  /*
   * "Not measured" is honest but it is a dead end — the answer lived four
   * clicks away in the row menu of a different screen. Walking every inode is
   * still the user's decision, never a side effect of opening this page, so it
   * is a control on the tile rather than something the card does on its own.
   */
  async function measure() {
    setMeasuring(true);
    try {
      await measureApplicationSize(application.id);
      router.refresh();
    } catch (error) {
      // Throttled, and it refuses outright for a site with no directory on
      // disk — both are real answers worth passing on verbatim.
      toast.error(apiMessage(error, t("size.measureFailed")));
    } finally {
      setMeasuring(false);
    }
  }

  // The one number here that changes on its own. formatBytes returns null for a
  // site nobody has measured — "Not measured" is the honest answer, and it is
  // the same word the sites list uses, where the ⋯ menu can do something about
  // it. A tile showing "0 B" for an unmeasured site would be a lie.
  const size = formatBytes(application.directory_size_bytes, format);

  const facts = [
    { icon: Package, label: t("columns.type"), value: application.site_type_title ?? application.site_type },
    { icon: User, label: t("columns.owner"), value: application.system_user?.username, mono: true },
    {
      icon: FolderTree,
      label: t("facts.webRoot"),
      value: application.web_root,
      mono: true,
      copy: true,
      // The one fact on this card that is a setting rather than a record.
      onEdit: canManage ? () => setEditingWebRoot(true) : null,
    },
    { icon: FileCode, label: t("facts.php"), value: application.php_version, mono: true },
    { icon: Hexagon, label: t("facts.node"), value: application.node_version, mono: true },
    { icon: Plug, label: t("facts.port"), value: application.app_port, mono: true, copy: true },
    {
      icon: HardDrive,
      label: t("columns.size"),
      value: size ?? t("size.notMeasured"),
      action: canManage
        ? { onClick: measure, busy: measuring, label: t("size.measureHint") }
        : null,
    },
    { icon: CalendarClock, label: t("columns.created"), value: application.created_at_human },
  ].filter((fact) => fact.value !== null && fact.value !== undefined && fact.value !== "");

  return (
    <Card className={className}>
      <CardHeader>
        <CardTitle as="h2" className="flex items-center gap-2 text-lg font-semibold">
          <Info className="size-4 text-primary" />
          {t("facts.title")}
        </CardTitle>
        <CardDescription>{t("facts.description")}</CardDescription>
      </CardHeader>
      <CardContent className={`grid gap-2 sm:grid-cols-2 ${factColumns(facts.length)}`}>
        {facts.map((fact) => (
          <Fact key={fact.label} {...fact} editLabel={t("webRoot.title")} />
        ))}
      </CardContent>

      <WebRootDialog
        application={application}
        open={editingWebRoot}
        onOpenChange={setEditingWebRoot}
      />
    </Card>
  );
}
