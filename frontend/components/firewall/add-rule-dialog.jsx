import { useEffect, useMemo, useRef, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  Check,
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
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
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

// One port field accepts `443` or `8000-8090` (split on submit); service chips only fill the port.
function valuesFrom(rule, initial = null) {
  if (!rule) return initial ? { ...DEFAULTS, ...initial } : DEFAULTS;
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
  // Editing: the caller owns open state and passes the rule. Creating: this owns its trigger.
  rule = null,
  // Creating from a suggestion or a blocked port: opened by the caller, pre-filled.
  initial = null,
  // Whether the firewall is enforcing (`ProtectedRuleGuard` locks seeded rules only while it is).
  firewallEnabled = false,
  onClose,
}) {
  const t = useTranslations("firewall");
  const { refreshAndWait } = useRefresh();
  const editing = rule !== null;
  // Fields the API refuses to change on a panel-seeded rule (renames are allowed).
  // Locked even when the firewall is off: enabling it re-adds the panel's allow rule AFTER this one
  // and ufw matches the first, so an SSH rule edited to Block would lock the user out.
  const ruleLocked = editing && Boolean(rule.protected);
  // The caller owns open state when it passes a rule or pre-filled values.
  const controlled = editing || initial !== null;
  const [selfOpen, setSelfOpen] = useState(false);
  const open = controlled ? true : selfOpen;
  const setOpen = (next) => {
    if (controlled) {
      if (!next) onClose?.();
      return;
    }
    // Reset here too: Radix skips onOpenChange for our own Cancel/trigger, and create mode never unmounts.
    if (!next) resetForm();
    setSelfOpen(next);
  };

  const services = useMemo(() => presets.filter((p) => p.port != null), [presets]);

  const form = useForm({
    resolver: zodResolver(createFirewallRuleSchema),
    defaultValues: valuesFrom(rule, initial),
    // Not on blur: clicking "Only my IP" would blur and flag the empty port field.
    mode: "onSubmit",
    reValidateMode: "onChange",
  });

  const values = useWatch({ control: form.control });
  const blocking = values.action === "deny";

  // The last auto-filled name: replaced when the service changes; user-typed names never are.
  const [autoName, setAutoName] = useState(initial?.description ?? "");

  // Everything a closed dialog must forget: values, field errors and the server's refusal.
  function resetForm() {
    setAutoName(initial?.description ?? "");
    form.reset(valuesFrom(rule, initial));
  }

  // A service fills its usual protocol (DNS is UDP) until someone picks a protocol
  // themselves; after that the shortcut keeps theirs, so the HTTPS chip with UDP chosen
  // means UDP 443, as the Add rule button does (Krishna, 24 Nov).
  const protocolChosen = form.getFieldState("protocol", form.formState).isDirty;
  const presetProtocol = (preset) => (protocolChosen ? values.protocol : (preset.protocol ?? "tcp"));

  function choosePreset(key) {
    if (!key) return;
    form.setValue("preset", key);
    const next = presets.find((p) => p.key === key);
    if (next?.port != null) {
      form.setValue("ports", String(next.port), { shouldValidate: true });
      // Not dirtied: a service's protocol is not the user's choice.
      if (!protocolChosen && next.protocol) form.setValue("protocol", next.protocol);

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
      // 422 is usually a duplicate rule; shown on the form, not pinned to Port (the server compares all fields).
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
  // ufw keeps one rule per port, protocol and source, so the server refuses a second one
  // whatever its action (RefusesDuplicateRules): the same action is a duplicate, the
  // opposite one a conflict. Said here, before the request, rather than as a 422.
  const clashWith = (from, to, protocol) =>
    rules.find(
      (r) =>
        r.id !== rule?.id &&
        Number(r.port_from) === from &&
        Number(r.port_to ?? 0) === Number(to ?? 0) &&
        (r.protocol ?? "tcp") === protocol &&
        (r.source_ip ?? "") === (values.source_ip?.trim() ?? ""),
    );
  const clashReason = (clash) =>
    !clash ? null : (clash.action ?? "allow") === values.action ? t("add.duplicate") : t("add.conflict");
  const blockReason = parsed ? clashReason(clashWith(parsed.from, parsed.to, values.protocol)) : null;

  // The refusal is at the top of a dialog scrolled down to its button, so bring it into
  // view (Krishna, 24 Nov: nothing seemed to happen on Add rule).
  const serverErrorRef = useRef(null);
  useEffect(() => {
    if (serverError) serverErrorRef.current?.scrollIntoView({ block: "nearest", behavior: "smooth" });
  }, [serverError]);

  return (
    <>
      {/* Plain <Button>, like every other "add" in the app. */}
      {controlled ? null : (
        <ReasonTooltip reason={canManage ? null : DENIED}>
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
            {/* A rule that already exists is refused by the server, so it is not sent. */}
            <Button type="submit" disabled={isSubmitting || Boolean(blockReason)} disabledReason={blockReason}>
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
              ref={serverErrorRef}
              role="alert"
              className="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2.5 text-sm leading-relaxed text-destructive"
            >
              <TriangleAlert className="mt-0.5 size-4 shrink-0" />
              {serverError}
            </p>
          ) : null}

          {/* Not sticky: it fought the toggle items' z-10 inside a short dialog. */}
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

          {/* Said once above the locked fields; the name stays editable and the sentence says so. */}
          {ruleLocked ? <Caution>{t("add.protectedLocked")}</Caution> : null}

          {blockReason ? (
            <p className="rounded-lg border bg-muted/40 px-3 py-2.5 text-xs leading-relaxed text-muted-foreground">
              {blockReason}
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
                onValueChange={(next) => form.setValue("protocol", next, { shouldDirty: true, shouldValidate: form.formState.isSubmitted })}
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
                {services.map((p) => {
                  // Already a rule for this service with the chosen action and source: the
                  // chip would only lead to "already exists" (Krishna, 24 Nov).
                  const taken = clashReason(clashWith(Number(p.port), null, presetProtocol(p)));
                  const item = (
                    <ToggleGroupItem key={p.key} value={p.key} disabled={Boolean(taken)} className="gap-1 px-2.5 text-xs">
                      {taken ? <Check className="size-3" aria-hidden /> : null}
                      {p.label}
                    </ToggleGroupItem>
                  );
                  return taken ? (
                    <ReasonTooltip key={p.key} reason={taken}>
                      {item}
                    </ReasonTooltip>
                  ) : (
                    item
                  );
                })}
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
      <p className="text-xs font-medium text-muted-foreground">
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

// Zod carries a key, the server a finished sentence; `t()` on the latter would return the key.
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
