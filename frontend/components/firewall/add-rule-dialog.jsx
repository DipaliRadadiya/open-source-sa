import { useMemo, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  CircleCheck,
  CircleSlash,
  Crosshair,
  Loader2,
  Pencil,
  Plus,
  ShieldPlus,
  TriangleAlert,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { createFirewallRule, updateFirewallRule } from "@/lib/api/firewall";
import { riskyExposure } from "@/lib/firewall/exposure";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import {
  createFirewallRuleSchema,
  parsePorts,
  CUSTOM_PRESET,
} from "@/lib/schemas/firewall";
import { Button } from "@/components/ui/button";
import { Caution } from "@/components/ui/caution";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { RequiredMark } from "@/components/ui/form";
import { FormModal } from "@/components/ui/form-modal";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const DEFAULTS = {
  preset: CUSTOM_PRESET,
  ports: "",
  protocol: "tcp",
  action: "allow",
  source_ip: "",
  description: "",
};

/**
 * The custom rule, for what Quick add can't make. The summary leads, Port comes
 * before Protocol, and one port field accepts `443` or `8000-8090` (split on
 * submit). Service chips only fill the port; choosing services is Quick add's job.
 */
function valuesFrom(rule) {
  if (!rule) return DEFAULTS;
  return {
    preset: CUSTOM_PRESET,
    ports: rule.port_to ? `${rule.port_from}-${rule.port_to}` : String(rule.port_from ?? ""),
    protocol: rule.protocol ?? "tcp",
    action: rule.action ?? "allow",
    source_ip: rule.source_ip ?? "",
    description: rule.description ?? "",
  };
}

export function AddRuleDialog({
  presets = [],
  rules = [],
  canManage,
  yourIp,
  riskyPorts = [],
  // Editing: the caller owns open state and passes the rule. Creating: this
  // component owns its trigger button and state.
  rule = null,
  // Whether the firewall is enforcing (`ProtectedRuleGuard` locks seeded rules only
  // while it is).
  firewallEnabled = false,
  onClose,
}) {
  const t = useTranslations("firewall");
  const { refreshAndWait } = useRefresh();
  const editing = rule !== null;
  // Fields the API refuses to change on a panel-seeded rule. The name is not
  // included: the guard allows renames, so the fields lock, not the button.
  // Locked even when the firewall is off: enabling it re-adds the panel's own allow
  // rule AFTER the edited one and ufw matches the first, so an SSH rule edited to
  // Block would lock the user out.
  const ruleLocked = editing && Boolean(rule.protected);
  const [selfOpen, setSelfOpen] = useState(false);
  const open = editing ? true : selfOpen;
  const setOpen = (next) => {
    if (editing) {
      if (!next) onClose?.();
      return;
    }
    // Reset here too: Radix fires onOpenChange for Esc/overlay/X but not for our own
    // Cancel or trigger, and in create mode this component never unmounts.
    if (!next) resetForm();
    setSelfOpen(next);
  };

  const services = useMemo(() => presets.filter((p) => p.port != null), [presets]);

  const form = useForm({
    resolver: zodResolver(createFirewallRuleSchema),
    defaultValues: valuesFrom(rule),
    // Not on blur: e.g. clicking "Only my IP" would blur the empty port field and
    // flag it. Errors show on save and clear as the user types.
    mode: "onSubmit",
    reValidateMode: "onChange",
  });

  const values = useWatch({ control: form.control });
  const blocking = values.action === "deny";

  // The last name this dialog filled in itself. User-typed names are never
  // overwritten, but an auto-filled one is replaced when the service changes.
  const [autoName, setAutoName] = useState("");

  // Everything a closed dialog must forget: values, field errors and the server's
  // refusal banner.
  function resetForm() {
    setAutoName("");
    form.reset(valuesFrom(rule));
  }

  function choosePreset(key) {
    if (!key) return;
    form.setValue("preset", key);
    const next = presets.find((p) => p.key === key);
    if (next?.port != null) {
      form.setValue("ports", String(next.port), { shouldValidate: true });
      if (next.protocol) form.setValue("protocol", next.protocol);

      const current = form.getValues("description")?.trim() ?? "";
      if (!current || current === autoName) {
        form.setValue("description", next.label);
        setAutoName(next.label);
      }
    }
    form.clearErrors("ports");
  }

  async function onSubmit(submitted) {
    // Not bound to a field, so nothing else clears the previous refusal.
    form.clearErrors("root.server");
    const parsed = parsePorts(submitted.ports);
    const payload = {
      port_from: parsed.from,
      protocol: submitted.protocol,
      action: submitted.action,
    };
    if (parsed.to) payload.port_to = parsed.to;
    if (submitted.source_ip?.trim()) payload.source_ip = submitted.source_ip.trim();
    if (submitted.description?.trim()) payload.description = submitted.description.trim();

    try {
      if (editing) {
        // Explicit nulls: on an edit, empty means CLEAR; on a create it means absent.
        payload.port_to = parsed.to ?? null;
        payload.source_ip = submitted.source_ip?.trim() || null;
        payload.description = submitted.description?.trim() || null;
        await updateFirewallRule(rule.id, payload);
        await refreshAndWait();
        toast.success(t("edit.saved"));
      } else {
        await createFirewallRule(payload);
        await refreshAndWait();
        toast.success(t("add.created"));
      }
      setOpen(false);
      setAutoName("");
      form.reset(editing ? valuesFrom(rule) : DEFAULTS);
    } catch (error) {
      // 422 is usually a duplicate rule: shown on the form, not as a toast. Not pinned
      // to Port, since the server compares port, protocol, action and source.
      handleValidationError(error, form, { formError: true });
    }
  }

  const { isSubmitting } = form.formState;
  const portError = form.formState.errors.ports?.message;
  const protocolError = form.formState.errors.protocol?.message;
  const sourceError = form.formState.errors.source_ip?.message;
  const nameError = form.formState.errors.description?.message;
  const serverError = form.formState.errors.root?.server?.message;

  const parsed = parsePorts(values.ports);
  // Warned before submit: the form already has what it needs.
  const risky = riskyExposure({
    port: parsed?.from,
    portTo: parsed?.to,
    action: values.action,
    source: values.source_ip,
    riskyPorts,
  });
  const duplicate =
    parsed &&
    rules.some(
      (r) =>
        r.id !== rule?.id &&
        Number(r.port_from) === parsed.from &&
        Number(r.port_to ?? 0) === Number(parsed.to ?? 0) &&
        (r.protocol ?? "tcp") === values.protocol &&
        (r.action ?? "allow") === values.action &&
        (r.source_ip ?? "") === (values.source_ip?.trim() ?? ""),
    );

  return (
    <>
      {/* Plain <Button>, like every other "add" in the app. */}
      {editing ? null : (
        <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
          <Button disabled={!canManage} onClick={() => setOpen(true)}>
            <Plus className="size-4" />
            {t("add.action")}
          </Button>
        </ReasonTooltip>
      )}

      <FormModal
        open={open}
        onOpenChange={(next) => {
          if (isSubmitting) return;
          // setOpen handles the reset, so Cancel, Esc, overlay and X share one path.
          setOpen(next);
          if (!next && editing) resetForm();
        }}
        icon={editing ? Pencil : ShieldPlus}
        title={editing ? t("edit.title") : t("add.title")}
        description={editing ? t("edit.description") : t("add.description")}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        className="sm:max-w-xl"
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              onClick={() => setOpen(false)}
              disabled={isSubmitting}
            >
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? <Loader2 className="size-4 animate-spin" /> : null}
              {editing ? t("edit.submit") : t("add.submit")}
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          {/* What the server refused, kept on screen above the summary. */}
          {serverError ? (
            <p
              role="alert"
              className="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2.5 text-sm leading-relaxed text-destructive"
            >
              <TriangleAlert className="mt-0.5 size-4 shrink-0" />
              {serverError}
            </p>
          ) : null}

          {/* Not sticky: inside a short dialog it caused a z-index fight with the toggle
              items (which carry z-10). */}
          <div
            className={cn(
              "rounded-lg border px-3 py-2.5",
              blocking
                ? "border-destructive/30 bg-[color-mix(in_oklab,var(--destructive)_10%,var(--card))]"
                : "border-primary/25 bg-[color-mix(in_oklab,var(--primary)_8%,var(--card))]",
            )}
          >
            <p className={cn("text-sm leading-snug", blocking && "text-destructive")}>
              {t(blocking ? "add.summaryBlock" : "add.summaryAllow", {
                target: portWords(t, values),
                protocol: protocolWord(t, values.protocol),
                source: values.source_ip?.trim() || t("add.anywhere"),
              })}
            </p>
          </div>

          {/* Warns about a database open to the whole internet before the rule exists. */}
          {risky ? (
            <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 px-3 py-2.5 text-xs leading-relaxed text-warning">
              <TriangleAlert className="mt-0.5 size-4 shrink-0" />
              {t("add.riskyExposure", { name: risky })}
            </p>
          ) : null}

          {/* Said once above the locked fields instead of per-field tooltips; the name stays
              editable and the sentence says so. */}
          {ruleLocked ? <Caution>{t("add.protectedLocked")}</Caution> : null}

          {duplicate ? (
            <p className="rounded-lg border bg-muted/40 px-3 py-2.5 text-xs leading-relaxed text-muted-foreground">
              {t("add.duplicate")}
            </p>
          ) : null}

          <Group title={t("add.actionGroup")}>
            <ToggleGroup
              type="single"
              value={values.action}
              onValueChange={(next) => next && form.setValue("action", next)}
              variant="outline"
              disabled={ruleLocked}
              className="flex-wrap justify-start gap-2"
            >
              <ToggleGroupItem value="allow" className="gap-2 px-4">
                <CircleCheck className="size-4" />
                {t("add.actionAllow")}
              </ToggleGroupItem>
              <ToggleGroupItem value="deny" className="gap-2 px-4">
                <CircleSlash className="size-4" />
                {t("add.actionDeny")}
              </ToggleGroupItem>
            </ToggleGroup>
          </Group>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="fw-ports">
                {t("add.portsLabel")}
                <RequiredMark />
              </Label>
              <Input
                id="fw-ports"
                autoFocus
                inputMode="numeric"
                placeholder={t("add.portsPlaceholder")}
                className="font-mono"
                aria-invalid={Boolean(portError)}
                disabled={ruleLocked}
                {...form.register("ports")}
              />
              <p className="text-xs leading-relaxed text-muted-foreground">
                {t("add.portsHint")}
              </p>
              {portError ? (
                <p role="alert" className="text-xs text-destructive">
                  {message(t, portError)}
                </p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="fw-protocol" hint={t("add.protocolHint")}>{t("add.protocol")}</Label>
              <Select
                value={values.protocol}
                onValueChange={(next) => form.setValue("protocol", next, { shouldValidate: form.formState.isSubmitted })}
              >
                <SelectTrigger id="fw-protocol" className="w-full" disabled={ruleLocked} aria-invalid={Boolean(protocolError)}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="tcp">TCP</SelectItem>
                  <SelectItem value="udp">UDP</SelectItem>
                  <SelectItem value="all">{t("add.protocolAll")}</SelectItem>
                </SelectContent>
              </Select>
              {protocolError ? (
                <p role="alert" className="text-xs text-destructive">
                  {message(t, protocolError)}
                </p>
              ) : null}
            </div>
          </div>

          {/* A shortcut for filling the port, hence the quiet label and small chips. */}
          {services.length > 0 ? (
            <div className="space-y-2">
              <p className="text-xs text-muted-foreground">{t("add.orPickService")}</p>
              <ToggleGroup
                type="single"
                value={values.preset}
                onValueChange={choosePreset}
                variant="outline"
                size="sm"
                className="flex-wrap justify-start gap-1.5"
              >
                {services.map((p) => (
                  <ToggleGroupItem key={p.key} value={p.key} className="px-2.5 text-xs">
                    {p.label}
                  </ToggleGroupItem>
                ))}
              </ToggleGroup>
            </div>
          ) : null}

          <div className="space-y-2">
            <Label htmlFor="fw-source" hint={t("add.sourceHint")}>{t("add.source")}</Label>
            <div className="flex flex-wrap items-center gap-2">
              <Input
                id="fw-source"
                disabled={ruleLocked}
                placeholder={t("add.sourcePlaceholder")}
                className="font-mono sm:max-w-64"
                autoComplete="off"
                spellCheck={false}
                aria-invalid={Boolean(sourceError)}
                {...form.register("source_ip")}
              />
              {/* One click makes the safe option the easy one. */}
              {yourIp ? (
                <Button
                  type="button"
                  variant="secondary"
                  className="gap-1.5"
                  onClick={() => form.setValue("source_ip", yourIp, { shouldDirty: true })}
                  // Writes into the source field, so it must follow the same lock.
                  disabled={ruleLocked || values.source_ip === yourIp}
                  disabledReason={ruleLocked ? t("rules.protectedReason") : t("add.onlyMyIpAlready")}
                >
                  <Crosshair className="size-4" />
                  {values.source_ip === yourIp ? t("add.onlyMyIpSet") : t("add.onlyMyIp")}
                </Button>
              ) : null}
            </div>
            {sourceError ? (
              <p role="alert" className="text-xs text-destructive">
                {message(t, sourceError)}
              </p>
            ) : null}
          </div>

          <div className="space-y-2">
            <Label htmlFor="fw-name">{t("add.nameLabel")}</Label>
            <Input
              id="fw-name"
              // The API's own limit.
              maxLength={255}
              placeholder={t("add.namePlaceholder")}
              aria-invalid={Boolean(nameError)}
              {...form.register("description")}
            />
            <p className="text-xs leading-relaxed text-muted-foreground">
              {t("add.nameHint")}
            </p>
            {/* Server errors for this field need their own slot to be shown. */}
            {nameError ? (
              <p role="alert" className="text-xs text-destructive">
                {message(t, nameError)}
              </p>
            ) : null}
          </div>
        </div>
      </FormModal>
    </>
  );
}

function Group({ title, children }) {
  return (
    <div className="space-y-2">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {title}
      </p>
      {children}
    </div>
  );
}

/** What the port field currently means, in words the summary can use. */
function portWords(t, values) {
  const parsed = parsePorts(values.ports);
  if (!parsed) return t("add.noPortYet");
  return parsed.to ? `${parsed.from}–${parsed.to}` : String(parsed.from);
}

function protocolWord(t, protocol) {
  return protocol === "all" ? t("add.protocolAllShort") : String(protocol).toUpperCase();
}

/**
 * Zod carries a key; the server carries a finished sentence. Translate ours, pass
 * theirs through (a server message run through `t()` comes out as the key).
 */
function message(t, text) {
  const KEYS = [
    "required",
    "portShape",
    "portRange",
    "portOrder",
    "invalidSource",
    "nameTooLong",
    "rangeNeedsProtocol",
  ];
  return KEYS.includes(text) ? t(`add.errors.${text}`) : text;
}
