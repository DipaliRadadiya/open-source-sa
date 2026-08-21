"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ShieldX, Trash2, Pencil } from "lucide-react";
import { deleteFirewallRule, updateFirewallRule } from "@/lib/api/firewall";
import { unreachablePorts } from "@/lib/firewall/listening";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
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
} from "@/components/firewall/rule-parts";
import { apiMessage } from "@/lib/api/error-message";

// A UFW rule list is short by nature — a handful to a few dozen. Search earns
// its space past this, and paging never does: 20 rules paged is worse than 20
// rules scrolled.
const SEARCH_FROM = 8;

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

function AddedCell({ row }) {
  return (
    <span className="whitespace-nowrap text-xs text-muted-foreground">
      {row.original.created_at_human || "—"}
    </span>
  );
}

function ActionsCell({ row, table }) {
  const { enabled, canManage, pending, onDelete, onToggle, onRename, shownEnabled, labels } =
    table.options.meta;
  const rule = row.original;
  const busy = pending === rule.id;

  return (
    <div className="flex items-center justify-end gap-1">
      {/* Off keeps the rule and stops enforcing it. Testing whether a rule
          matters used to mean deleting it — and for a deny rule, that lets the
          blocked thing through while you work out how to retype it. */}
      <ReasonTooltip reason={canManage ? null : labels.noPermission}>
        <PendingSwitch
          checked={shownEnabled(rule)}
          pending={busy}
          onCheckedChange={() => onToggle(rule)}
          disabled={!canManage}
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
  enabled,
  presets,
  canManage,
  isAdmin,
  yourIp,
  riskyPorts = [],
  listening = [],
}) {
  const t = useTranslations("firewall");
  const router = useRouter();
  const [query, setQuery] = useState("");
  const [pending, setPending] = useState(null);
  const [confirming, setConfirming] = useState(null);
  const [editing, setEditing] = useState(null);
  // The state the user asked for, held until the server catches up — stored
  // WITH the value it was based on, so the override retires itself the moment
  // the refreshed rule moves off that value. Without it this switch sat still
  // for the ~1.3s the write takes and the click read as ignored; the fail2ban
  // jail switch has worked this way since 7e1c6cc and these two should not
  // behave differently.
  const [asked, setAsked] = useState({});

  const term = query.trim().toLowerCase();
  const filtered = term
    ? rules.filter((rule) =>
        [rule.description, rule.summary, String(rule.port_from), rule.source_ip]
          .filter(Boolean)
          .some((field) => String(field).toLowerCase().includes(term)),
      )
    : rules;

  // A rule with no name of its own is named after the service on its port. The
  // map is built from the API's own preset list, so it can't disagree with the
  // names offered in the add form.
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
    // What the switch is SHOWING, not what the server last said. `pending`
    // clears the moment the PUT resolves, but `rules` only changes when
    // `router.refresh()` lands — so a second click in that window read the
    // stale server value, re-sent the value it had just sent, and toasted
    // "switched off" for a click that meant on.
    const next = !shownEnabled(rule);
    setPending(rule.id);
    setAsked((current) => ({ ...current, [rule.id]: { value: next, from: rule.enabled !== false } }));
    try {
      await updateFirewallRule(rule.id, { enabled: next });
      toast.success(next ? t("rules.enabled") : t("rules.disabled"));
      router.refresh();
    } catch (error) {
      // Put it back: a switch must not sit showing a state the server refused.
      setAsked((current) => {
        const reverted = { ...current };
        delete reverted[rule.id];
        return reverted;
      });
      toast.error(
        apiMessage(error, t("rules.toggleFailed")),
      );
    } finally {
      setPending(null);
    }
  }

  function shownEnabled(rule) {
    const override = asked[rule.id];
    const server = rule.enabled !== false;
    return override && override.from === server ? override.value : server;
  }

  const blocked = unreachablePorts({ listening, rules, enabled });

  async function onDelete(rule) {
    setConfirming(rule);
  }

  async function confirmDelete() {
    const rule = confirming;
    setPending(rule.id);
    try {
      await deleteFirewallRule(rule.id);
      toast.success(t("rules.deleted"));
      setConfirming(null);
      router.refresh();
    } catch (error) {
      toast.error(
        apiMessage(error, t("rules.deleteFailed")),
      );
    } finally {
      setPending(null);
    }
  }

  // Columns, so ten rules can be compared down a column instead of read one
  // sentence at a time. Name leads: it is the only part a person wrote.
  const columns = [
    // Widths in percentages, because `w-24` on a table cell is a minimum, not a
    // cap: the two columns without one (name and source) absorbed everything
    // left over and squeezed the rest together.
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
          <HistoryDialog isAdmin={isAdmin} />
          <AddRuleDialog
            presets={presets}
            rules={rules}
            canManage={canManage}
            yourIp={yourIp}
            riskyPorts={riskyPorts}
          />
        </div>
      </CardHeader>

      <CardContent className="space-y-3">
        {/* The other half of "is this rule doing anything": something is
            listening on a public port and no rule lets it through. Only shown
            while the firewall is enforcing — otherwise nothing is blocked and
            the statement would be false. */}
        {blocked.length ? (
          <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm">
            {t("rules.unreachable", {
              ports: blocked.map((entry) => entry.port).join(", "),
              count: blocked.length,
            })}
          </p>
        ) : null}

        {rules.length > SEARCH_FROM ? (
          <Input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t("rules.search")}
            className="sm:max-w-72"
            autoComplete="off"
            spellCheck={false}
          />
        ) : null}

        {/* "Nothing here yet" and "nothing matched your search" are different
            problems with different fixes, so they are different messages. */}
        {rules.length === 0 ? (
          <EmptyState
            icon={ShieldX}
            title={t("rules.emptyTitle")}
            description={t("rules.emptyBody")}
          />
        ) : filtered.length === 0 ? (
          <p className="py-10 text-center text-sm text-muted-foreground">
            {t("rules.noMatches")}
          </p>
        ) : (
          <>
            <div className="lg:hidden">
              <RulesCards
                rules={filtered}
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
              {/* Sortable, low port first. Insertion order is the least
                  useful order once there are more than a few rules — you look
                  for a port, not for whichever rule was added third. */}
              <DataTable
                columns={columns}
                data={filtered}
                stickyHeader
                sortable
                defaultSorting={[{ id: "port_from", desc: false }]}
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

      {/* Deleting a rule is not reversible by undo — you re-add it — and while
          the firewall is on it changes what can reach the server immediately. */}
      {/* The same form as "Custom rule", seeded from the rule. Editing beats
          delete-and-recreate: the API adds the replacement before removing the
          old one, so a deny rule never stops denying in between. Keyed by id so
          each rule opens with its own values. */}
      {editing ? (
        <AddRuleDialog
          key={editing.id}
          rule={editing}
          rules={rules}
          presets={presets}
          canManage={canManage}
          yourIp={yourIp}
          riskyPorts={riskyPorts}
          onClose={() => setEditing(null)}
        />
      ) : null}

      <ConfirmDialog
        open={confirming !== null}
        onOpenChange={(open) => !pending && setConfirming(open ? confirming : null)}
        icon={Trash2}
        tone="destructive"
        title={t("rules.confirmTitle")}
        description={
          confirming
            ? enabled
              ? t("rules.confirmBodyOn", { rule: confirming.description || confirming.summary || confirming.port_from })
              : t("rules.confirmBodyOff", { rule: confirming.description || confirming.summary || confirming.port_from })
            : ""
        }
        cancelLabel={t("common.cancel")}
        confirmLabel={t("rules.delete")}
        pending={pending !== null}
        onConfirm={confirmDelete}
      />
    </Card>
  );
}
