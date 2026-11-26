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
  rule = null,
  initial = null,
  firewallEnabled = false,
  onClose,
}) {
  const t = useTranslations("firewall");
  const { refreshAndWait } = useRefresh();
  const editing = rule !== null;
  const ruleLocked = editing && Boolean(rule.protected);
  const controlled = editing || initial !== null;
  const [selfOpen, setSelfOpen] = useState(false);
  const open = controlled ? true : selfOpen;
  const setOpen = (next) => {
    if (controlled) {
      if (!next) onClose?.();
      return;
    }
    // Radix skips onOpenChange for our own Cancel/trigger, and create mode never unmounts.
    if (!next) resetForm();
    setSelfOpen(next);
  };

  const services = useMemo(() => presets.filter((p) => p.port != null), [presets]);

  const form = useForm({
    resolver: zodResolver(createFirewallRuleSchema),
    defaultValues: valuesFrom(rule, initial),
    mode: "onSubmit",
    reValidateMode: "onChange",
  });

  const values = useWatch({ control: form.control });
  const blocking = values.action === "deny";

  const [autoName, setAutoName] = useState(initial?.description ?? "");

  function resetForm() {
    setAutoName(initial?.description ?? "");
    form.reset(valuesFrom(rule, initial));
  }

  const protocolChosen = form.getFieldState("protocol", form.formState).isDirty;
  const presetProtocol = (preset) => (protocolChosen ? values.protocol : (preset.protocol ?? "tcp"));

  function choosePreset(key) {
    if (!key) return;
    form.setValue("preset", key);
    const next = presets.find((p) => p.key === key);
    if (next?.port != null) {
      form.setValue("ports", String(next.port), { shouldValidate: true });
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
  const risky = riskyExposure({
    port: parsed?.from,
    portTo: parsed?.to,
    action: values.action,
    source: values.source_ip,
    riskyPorts,
  });
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

  const serverErrorRef = useRef(null);
  useEffect(() => {
    if (serverError) serverErrorRef.current?.scrollIntoView({ block: "nearest", behavior: "smooth" });
  }, [serverError]);

  return (
    <>
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
            <Button type="submit" disabled={isSubmitting || Boolean(blockReason)} disabledReason={blockReason}>
              {isSubmitting ? <Loader2 className="size-4 animate-spin" /> : null}
              {editing ? t("edit.submit") : t("add.submit")}
            </Button>
          </>
        }
      >
        <div className="space-y-5">
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

          {risky ? (
            <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 px-3 py-2.5 text-xs leading-relaxed text-warning">
              <TriangleAlert className="mt-0.5 size-4 shrink-0" />
              {t("add.riskyExposure", { name: risky })}
            </p>
          ) : null}

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
              {yourIp ? (
                <Button
                  type="button"
                  variant="secondary"
                  className="gap-1.5"
                  onClick={() => form.setValue("source_ip", yourIp, { shouldDirty: true })}
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
              maxLength={255}
              placeholder={t("add.namePlaceholder")}
              aria-invalid={Boolean(nameError)}
              {...form.register("description")}
            />
            <p className="text-xs leading-relaxed text-muted-foreground">
              {t("add.nameHint")}
            </p>
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

function portWords(t, values) {
  const parsed = parsePorts(values.ports);
  if (!parsed) return t("add.noPortYet");
  return parsed.to ? `${parsed.from}–${parsed.to}` : String(parsed.from);
}

function protocolWord(t, protocol) {
  return protocol === "all" ? t("add.protocolAllShort") : String(protocol).toUpperCase();
}

// Zod gives a key, the server a finished sentence; t() on a sentence returns it unchanged.
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
