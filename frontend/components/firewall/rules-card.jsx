"use client";

import { useBrowserIp } from "@/components/network/browser-ip";
import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { SearchX, ShieldX, Trash2, Pencil } from "lucide-react";
import { deleteFirewallRule, updateFirewallRule } from "@/lib/api/firewall";
import { deleteRuleBodyKey } from "@/lib/firewall/state";
import { unreachablePorts } from "@/lib/firewall/listening";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { usePendingKeys } from "@/hooks/use-pending-keys";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { SearchInput } from "@/components/data-table/search-input";
import { useSetQuery } from "@/hooks/use-set-query";
import { FacetSelect } from "@/components/data-table/facet-select";
import { RefreshButton } from "@/components/data-table/refresh-button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { AddRuleDialog } from "@/components/firewall/add-rule-dialog";
import { HistoryDialog } from "@/components/firewall/history-dialog";
import { RulesCards } from "@/components/firewall/rules-cards";
import {
  RuleName,
  ActionBadge,
  PortText,
  ProtocolText,
  SourceText,
  DeleteRuleButton,
  protectedReasonFor,
} from "@/components/firewall/rule-parts";
import { apiMessage } from "@/lib/api/error-message";

/* Cells at module level — flexRender treats a cell function's identity as the
 * component type, so an inline cell remounts on every render. */

function NameCell({ row, table }) {
  const { enabled, labels } = table.options.meta;
  return <RuleName rule={row.original} muted={!enabled} labels={labels} />;
}

function ActionCell({ row, table }) {
  return <ActionBadge rule={row.original} labels={table.options.meta.labels} />;
}

function ProtocolCell({ row, table }) {
  return <ProtocolText rule={row.original} labels={table.options.meta.labels} />;
}

function PortCell({ row }) {
  return <PortText rule={row.original} />;
}

function SourceCell({ row, table }) {
  return <SourceText rule={row.original} labels={table.options.meta.labels} />;
}

function ActionsCell({ row, table }) {
  const { enabled, canManage, pending, onDelete, onToggle, onRename, shownEnabled, labels } =
    table.options.meta;
  const rule = row.original;
  const busy = pending.includes(rule.id);
  // Same guard as the API: switching a seeded rule off is, to ufw, a delete.
  // A protected rule that is off can be switched back on, the only way out of a lock-out.
  const guarded = protectedReasonFor({
    rule,
    enabled,
    canManage,
    labels,
    turningOff: shownEnabled(rule),
  });

  return (
    <div className="flex items-center justify-end gap-1">
      {/* Off keeps the rule but stops enforcing it, so a rule can be tested without
          deleting it. */}
      <ReasonTooltip reason={guarded}>
        <PendingSwitch
          checked={shownEnabled(rule)}
          pending={busy}
          onCheckedChange={() => onToggle(rule)}
          disabled={Boolean(guarded)}
          aria-label={labels.toggle}
        />
      </ReasonTooltip>

      <ReasonTooltip reason={canManage ? null : labels.noPermission}>
        <Button
          variant="ghost"
          size="sm"
          disabled={!canManage || busy}
          onClick={() => onRename(rule)}
          aria-label={labels.rename}
        >
          <Pencil className="size-4" />
        </Button>
      </ReasonTooltip>

      <DeleteRuleButton
        rule={rule}
        enabled={enabled}
        canManage={canManage}
        pending={busy}
        onDelete={onDelete}
        labels={labels}
      />
    </div>
  );
}

export function RulesCard({
  rules,
  allRules = rules,
  enabled,
  presets,
  canManage,
  historyForEveryone,
  riskyPorts = [],
  listening = [],
}) {
  const t = useTranslations("firewall");
  // The reader's address as the browser sees it; see components/network/browser-ip.jsx.
  const yourIp = useBrowserIp();
  const { refreshAndWait } = useRefresh();
  const searchParams = useSearchParams();
  const tc = useTranslations("common");
  const setQuery = useSetQuery();
  const hasFilters = ["search", "enabled", "action", "origin", "sort"].some((key) => searchParams.has(key));
  // Several switches can be in flight at once, so each tracks its own state; delete
  // runs from a dialog that stays open until done.
  const toggling = usePendingKeys();
  const [deletingId, setDeletingId] = useState(null);
  const pending = deletingId === null ? toggling.pendingKeys : [...toggling.pendingKeys, deletingId];
  const [confirming, setConfirming] = useState(null);
  const [editing, setEditing] = useState(null);
  // The requested state, stored with the value it was based on so it retires once the rule moves off it.
  const [asked, setAsked] = useState({});

  // An unnamed rule is named after the service on its port, from the API's preset
  // list, so it matches the names in the add form.
  const byPort = new Map(
    presets.filter((p) => p.port != null).map((p) => [Number(p.port), p.label]),
  );
  const nameFor = (rule) =>
    !rule.port_to && byPort.get(Number(rule.port_from))
      ? byPort.get(Number(rule.port_from))
      : rule.port_from
        ? t("rules.portName", { port: rule.port_from })
        : null;

  const labels = {
    nameFor,
    added: t("rules.added"),
    from: t("rules.from"),
    allow: t("rules.allow"),
    deny: t("rules.deny"),
    anywhere: t("rules.anywhere"),
    anyProtocol: t("rules.anyProtocol"),
    unnamed: t("rules.unnamed"),
    off: t("rules.off"),
    delete: t("rules.delete"),
    noPermission: t("disabled.noPermission"),
    protectedHint: t("rules.protectedHint"),
    protectedReason: t("rules.protectedReason"),
    toggle: t("rules.toggle"),
    rename: t("rules.edit"),
  };

  async function onToggle(rule) {
    // `rules` updates only when `router.refresh()` lands, so the server value can be stale for a second click.
    if (toggling.isPending(rule.id)) return;
    const next = !shownEnabled(rule);
    toggling.start(rule.id);
    setAsked((current) => ({ ...current, [rule.id]: { value: next, from: rule.enabled !== false } }));
    try {
      await updateFirewallRule(rule.id, { enabled: next });
      await refreshAndWait();
      toast.success(next ? t("rules.enabled") : t("rules.disabled"));
    } catch (error) {
      // Revert: a switch must not show a state the server refused.
      setAsked((current) => {
        const reverted = { ...current };
        delete reverted[rule.id];
        return reverted;
      });
      toast.error(
        apiMessage(error, t("rules.toggleFailed")),
      );
    } finally {
      toggling.finish(rule.id);
    }
  }

  function shownEnabled(rule) {
    const override = asked[rule.id];
    const server = rule.enabled !== false;
    return override && override.from === server ? override.value : server;
  }

  const blocked = unreachablePorts({ listening, rules: allRules, enabled });

  async function onDelete(rule) {
    setConfirming(rule);
  }

  async function confirmDelete() {
    const rule = confirming;
    setDeletingId(rule.id);
    try {
      await deleteFirewallRule(rule.id);
      await refreshAndWait();
      toast.success(t("rules.deleted"));
      setConfirming(null);
    } catch (error) {
      toast.error(
        apiMessage(error, t("rules.deleteFailed")),
      );
    } finally {
      setDeletingId(null);
    }
  }

  // Columns, so rules can be compared down a column. Name leads: it is the only
  // part a person wrote.
  const columns = [
    // Percent widths: `w-24` on a table cell is a minimum, not a cap, so the unsized
    // columns would absorb all the slack.
    {
      accessorKey: "description",
      header: t("rules.name"),
      meta: { className: "w-[24%]" },
      cell: NameCell,
    },
    { id: "action", header: t("rules.action"), meta: { className: "w-[12%]" }, cell: ActionCell },
    {
      id: "protocol",
      header: t("rules.protocol"),
      meta: { className: "w-[12%]" },
      cell: ProtocolCell,
    },
    {
      accessorKey: "port_from",
      header: t("rules.port"),
      meta: { className: "w-[12%]" },
      cell: PortCell,
    },
    { id: "source", header: t("rules.source"), meta: { className: "w-[36%]" }, cell: SourceCell },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("rules.actions")}</span>,
      meta: { className: "w-32 text-right" },
      cell: ActionsCell,
    },
  ];

  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 space-y-0 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-1">
          <CardTitle className="text-base font-semibold">{t("rules.title")}</CardTitle>
          <CardDescription>
            {enabled ? t("rules.description") : t("rules.descriptionOff")}
          </CardDescription>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <RefreshButton />
          <HistoryDialog everyone={historyForEveryone} />
          <AddRuleDialog
            presets={presets}
            rules={allRules}
            canManage={canManage}
            yourIp={yourIp}
            riskyPorts={riskyPorts}
          />
        </div>
      </CardHeader>

      <CardContent className="space-y-3">
        {/* Public ports with no rule letting them through. Only while the firewall is
            enforcing; otherwise nothing is blocked. */}
        {blocked.length ? (
          <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm">
            {t("rules.unreachable", {
              ports: blocked.map((entry) => entry.port).join(", "),
              count: blocked.length,
            })}
          </p>
        ) : null}

        {/* Widths from the widest option in all 8 languages (fr "Utilisateur de base de données"). */}
        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
          <SearchInput placeholder={t("rules.search")} />
          <FacetSelect
            paramKey="enabled"
            allLabel={t("rules.filters.anyEnabled")}
            label={t("rules.filters.stateLabel")}
            options={[
              { value: "1", label: t("rules.filters.enabled") },
              { value: "0", label: t("rules.filters.disabled") },
            ]}
            className="w-full sm:w-44"
          />
          <FacetSelect
            paramKey="action"
            allLabel={t("rules.filters.anyAction")}
            label={t("rules.filters.actionLabel")}
            options={[
              { value: "allow", label: t("rules.allow") },
              { value: "deny", label: t("rules.deny") },
            ]}
            className="w-full sm:w-48"
          />
          <FacetSelect
            paramKey="origin"
            allLabel={t("rules.filters.anyOrigin")}
            label={t("rules.filters.originLabel")}
            options={[
              { value: "user", label: t("rules.filters.user") },
              { value: "default", label: t("rules.filters.default") },
              { value: "db_user", label: t("rules.filters.database") },
            ]}
            className="w-full sm:w-72"
          />
          <FacetSelect
            paramKey="sort"
            allLabel={t("rules.filters.newest")}
            label={t("rules.filters.sortLabel")}
            options={[
              { value: "port_from", label: t("rules.filters.portAsc") },
              { value: "-port_from", label: t("rules.filters.portDesc") },
              { value: "action", label: t("rules.filters.actionAsc") },
              { value: "protocol", label: t("rules.filters.protocolAsc") },
            ]}
            className="w-full sm:w-56"
          />
        </div>

        {rules.length === 0 ? (
          hasFilters ? (
            // Five filters live in the URL; the button clears all of them, including sort.
            <EmptyState
              icon={SearchX}
              title={t("rules.noMatches")}
              action={
                <Button
                  variant="outline"
                  onClick={() =>
                    setQuery(
                      {
                        search: undefined,
                        enabled: undefined,
                        action: undefined,
                        origin: undefined,
                        sort: undefined,
                      },
                      { resetPage: true },
                    )
                  }
                >
                  {tc("clearFilters")}
                </Button>
              }
            />
          ) : (
            <EmptyState
              icon={ShieldX}
              title={t("rules.emptyTitle")}
              description={t("rules.emptyBody")}
            />
          )
        ) : (
          <>
            <div className="lg:hidden">
              <RulesCards
                rules={rules}
                enabled={enabled}
                canManage={canManage}
                pending={pending}
                onDelete={onDelete}
                onToggle={onToggle}
                shownEnabled={shownEnabled}
                onRename={setEditing}
                labels={labels}
              />
            </div>

            <div className="hidden max-h-[30rem] overflow-auto rounded-xl border lg:block [&>div]:rounded-none [&>div]:border-0">
              {/* The API owns sort order, so pages are never re-sorted locally. */}
              <DataTable
                columns={columns}
                data={rules}
                stickyHeader
                meta={{
                  enabled,
                  canManage,
                  pending,
                  onDelete,
                  onToggle,
                  shownEnabled,
                  onRename: setEditing,
                  labels,
                }}
              />
            </div>
          </>
        )}
      </CardContent>

      {/* Edit, not delete-and-recreate: the API adds the replacement first, so a deny rule never lapses.
          Keyed by id so each rule opens with its own values. */}
      {editing ? (
        <AddRuleDialog
          key={editing.id}
          rule={editing}
          firewallEnabled={enabled}
          rules={allRules}
          presets={presets}
          canManage={canManage}
          yourIp={yourIp}
          riskyPorts={riskyPorts}
          onClose={() => setEditing(null)}
        />
      ) : null}

      <ConfirmDialog
        open={confirming !== null}
        onOpenChange={(open) => deletingId === null && setConfirming(open ? confirming : null)}
        icon={Trash2}
        tone="destructive"
        title={t("rules.confirmTitle")}
        description={
          confirming
            ? t(deleteRuleBodyKey(enabled, confirming), {
                rule: confirming.description || confirming.summary || confirming.port_from,
              })
            : ""
        }
        cancelLabel={t("common.cancel")}
        confirmLabel={t("rules.delete")}
        pending={deletingId !== null}
        onConfirm={confirmDelete}
      />
    </Card>
  );
}
