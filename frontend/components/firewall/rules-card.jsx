"use client";

import { useBrowserIp } from "@/components/network/browser-ip";
import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { SearchX, ShieldX, Trash2, Shield, BrickWall } from "lucide-react";
import { deleteFirewallRule, updateFirewallRule } from "@/lib/api/firewall";
import { deleteRuleBodyKey } from "@/lib/firewall/state";
import { unreachablePorts } from "@/lib/firewall/listening";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { usePendingKeys } from "@/hooks/use-pending-keys";
import { DENIED, ReasonTooltip, readOnlyReason } from "@/components/ui/reason-tooltip";
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
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { AddRuleDialog } from "@/components/firewall/add-rule-dialog";
import { HistoryDialog } from "@/components/firewall/history-dialog";
import { RulesCards } from "@/components/firewall/rules-cards";
import { Note } from "@/components/ui/note";
import {
  RuleName,
  ActionBadge,
  PortProtocol,
  SourceText,
  RuleRowMenu,
  ProtectedTag,
  isLockedOn,
  protectedReasonFor,
} from "@/components/firewall/rule-parts";
import { apiMessage } from "@/lib/api/error-message";

function NameCell({ row, table }) {
  const { enabled, labels } = table.options.meta;
  return <RuleName rule={row.original} muted={!enabled} labels={labels} />;
}

function ActionCell({ row, table }) {
  return <ActionBadge rule={row.original} labels={table.options.meta.labels} />;
}

function PortCell({ row, table }) {
  return <PortProtocol rule={row.original} labels={table.options.meta.labels} />;
}

function SourceCell({ row, table }) {
  return <SourceText rule={row.original} labels={table.options.meta.labels} />;
}

function StateCell({ row, table }) {
  const { canManage, pending, onToggle, shownEnabled, labels } = table.options.meta;
  const rule = row.original;
  const guarded = protectedReasonFor({ rule, canManage, labels, turningOff: shownEnabled(rule) });

  if (isLockedOn(rule, shownEnabled(rule))) return <ProtectedTag labels={labels} />;
  return (
    <ReasonTooltip reason={readOnlyReason(guarded)}>
      <PendingSwitch
        readOnly={!canManage}
        checked={shownEnabled(rule)}
        pending={pending.includes(rule.id)}
        onCheckedChange={() => onToggle(rule)}
        disabled={Boolean(guarded)}
        aria-label={labels.toggle}
      />
    </ReasonTooltip>
  );
}

function ActionsCell({ row, table }) {
  const { enabled, canManage, pending, onDelete, onRename, shownEnabled, labels } = table.options.meta;
  const rule = row.original;
  const busy = pending.includes(rule.id);
  const locked = isLockedOn(rule, shownEnabled(rule));

  return (
    <div className="flex justify-end">
      <RuleRowMenu
        rule={rule}
        enabled={enabled}
        canManage={canManage}
        pending={busy}
        onDelete={onDelete}
        onRename={onRename}
        deletable={!locked}
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
  total = rules.length,
}) {
  const t = useTranslations("firewall");
  const yourIp = useBrowserIp();
  const { refreshAndWait } = useRefresh();
  const searchParams = useSearchParams();
  const tc = useTranslations("common");
  const setQuery = useSetQuery();
  const hasFilters = ["search", "enabled", "action", "origin", "sort"].some((key) => searchParams.has(key));
  const toggling = usePendingKeys();
  const [deletingId, setDeletingId] = useState(null);
  const pending = deletingId === null ? toggling.pendingKeys : [...toggling.pendingKeys, deletingId];
  const [confirming, setConfirming] = useState(null);
  const [editing, setEditing] = useState(null);
  const [adding, setAdding] = useState(null);
  const [asked, setAsked] = useState({});

  const byPort = new Map(
    presets.filter((p) => p.port != null).map((p) => [Number(p.port), p.label]),
  );
  const nameFor = (rule) =>
    !rule.port_to && byPort.get(Number(rule.port_from))
      ? byPort.get(Number(rule.port_from))
      : rule.port_from
        ? t("rules.portName", { port: rule.port_from })
        : null;

  const listenerName = (entry) =>
    byPort.get(Number(entry.port)) ??
    (entry.program && !/thread/i.test(entry.program) ? entry.program : null);

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
    noPermission: DENIED,
    protectedHint: t("rules.protectedHint"),
    protectedReason: t("rules.protectedReason"),
    protectedTag: t("rules.protectedTag"),
    toggle: t("rules.toggle"),
    rename: t("rules.edit"),
    actions: t("rules.actions"),
  };

  async function onToggle(rule) {
    // rules only updates when router.refresh() lands, so it can be stale for a second click.
    if (toggling.isPending(rule.id)) return;
    const next = !shownEnabled(rule);
    toggling.start(rule.id);
    setAsked((current) => ({ ...current, [rule.id]: { value: next, from: rule.enabled !== false } }));
    try {
      await updateFirewallRule(rule.id, { enabled: next });
      await refreshAndWait();
      toast.success(next ? t("rules.enabled") : t("rules.disabled"));
    } catch (error) {
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

  const showFilters = total > 10 || hasFilters;

  const columns = [
    {
      accessorKey: "description",
      header: t("rules.name"),
      meta: { className: "w-[22%]" },
      cell: NameCell,
    },
    { id: "action", header: t("rules.action"), meta: { className: "w-[12%]" }, cell: ActionCell },
    {
      accessorKey: "port_from",
      header: t("rules.port"),
      meta: { className: "w-[15%]" },
      cell: PortCell,
    },
    { id: "source", header: t("rules.source"), meta: { className: "w-[23%]" }, cell: SourceCell },
    { id: "state", header: t("rules.filters.stateLabel"), meta: { className: "w-[14%]" }, cell: StateCell },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("rules.actions")}</span>,
      meta: { className: "w-24 text-right" },
      cell: ActionsCell,
    },
  ];

  return (
    <>
      {blocked.length ? (
        <Note title={t("rules.privateTitle", { count: blocked.length })}>
          <p>{t("rules.privateBody", { count: blocked.length })}</p>
          <ul className="flex flex-wrap gap-2 pt-1.5">
            {blocked.map((entry) => {
              const name = listenerName(entry);
              return (
                <li
                  key={`${entry.port}-${entry.protocol ?? ""}`}
                  className="inline-flex items-center gap-2 rounded-lg border bg-card py-1 ps-3 pe-1 text-sm text-foreground shadow-xs"
                >
                  {name ? (
                    <>
                      <span className="font-medium">{name}</span>
                      <span className="font-mono text-muted-foreground">{entry.port}</span>
                    </>
                  ) : (
                    <span className="font-medium">{t("rules.portName", { port: entry.port })}</span>
                  )}
                  {canManage ? (
                    <Button
                      type="button"
                      variant="ghost"
                      size="xs"
                      className="text-primary"
                      aria-label={t("rules.allowPort", { port: entry.port })}
                      onClick={() =>
                        setAdding({
                          ports: String(entry.port),
                          protocol: entry.protocol === "udp" ? "udp" : "tcp",
                        })
                      }
                    >
                      {t("rules.allow")}
                    </Button>
                  ) : null}
                </li>
              );
            })}
          </ul>
        </Note>
      ) : null}

      <Card>
        <CardHeader className="flex flex-col gap-3 space-y-0 sm:flex-row sm:items-center sm:justify-between">
          <CardTitle icon={BrickWall}>{t("rules.title")}</CardTitle>
          <div className="flex flex-wrap items-center gap-2">
            <RefreshButton />
            <HistoryDialog everyone={historyForEveryone} variant="neutral" />
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
          {showFilters ? (
            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
              <div className="sm:max-w-xs sm:min-w-56 sm:flex-1">
                <SearchInput placeholder={t("rules.search")} />
              </div>
              <FacetSelect
                paramKey="enabled"
                allLabel={t("rules.filters.anyEnabled")}
                label={t("rules.filters.stateLabel")}
                options={[
                  { value: "1", label: t("rules.filters.enabled") },
                  { value: "0", label: t("rules.filters.disabled") },
                ]}
                className="w-full sm:w-auto sm:min-w-36 sm:shrink-0"
              />
              <FacetSelect
                paramKey="action"
                allLabel={t("rules.filters.anyAction")}
                label={t("rules.filters.actionLabel")}
                options={[
                  { value: "allow", label: t("rules.allow") },
                  { value: "deny", label: t("rules.deny") },
                ]}
                className="w-full sm:w-auto sm:min-w-36 sm:shrink-0"
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
                className="w-full sm:w-auto sm:min-w-36 sm:shrink-0"
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
                className="w-full sm:w-auto sm:min-w-36 sm:shrink-0"
              />
            </div>
          ) : null}

          {rules.length === 0 ? (
            hasFilters ? (
              <EmptyState
                icon={SearchX}
                subject={Shield}
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
            <div className="@container">
              <div className="@min-[900px]/page:hidden">
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

              <div className="-mx-(--card-spacing) -mb-(--card-spacing) hidden max-h-[30rem] overflow-auto @min-[900px]/page:-mt-(--card-spacing) @min-[900px]/page:block">
                <DataTable
                  bare
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
            </div>
          )}
        </CardContent>

        {/* The API adds the replacement before removing the old one, so a deny rule never lapses. */}
        {adding ? (
          <AddRuleDialog
            key={JSON.stringify(adding)}
            initial={adding}
            rules={allRules}
            presets={presets}
            canManage={canManage}
            yourIp={yourIp}
            riskyPorts={riskyPorts}
            onClose={() => setAdding(null)}
          />
        ) : null}

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
    </>
  );
}
