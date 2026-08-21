"use client";

import { useMemo, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
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
 * The custom rule — the one Quick add can't make.
 *
 * Three things were wrong with the previous version and all three came from
 * treating this as the main path rather than the exception:
 *
 *   - **The summary was below the fold**, so the sentence explaining the rule
 *     was the one thing you had to scroll to reach. It leads now, pinned under
 *     the header where it is always in view.
 *   - **Protocol came before Port.** The port is the subject of the rule;
 *     protocol is a qualifier on it. Port leads and takes the width.
 *   - **"Up to port" was a whole field for a rare case.** One field now accepts
 *     `443` or `8000-8090` and splits it on submit — a forgiving format, rather
 *     than making the user fill in the API's data model.
 *
 * The service chips stay, but as a shortcut that fills the port, not as the
 * subject: choosing services is what Quick add is for.
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
  // Editing: the caller owns open/closed and passes the rule. Creating: this
  // component owns its own trigger button and state.
  rule = null,
  onClose,
}) {
  const t = useTranslations("firewall");
  const router = useRouter();
  const editing = rule !== null;
  const [selfOpen, setSelfOpen] = useState(false);
  const open = editing ? true : selfOpen;
  const setOpen = (next) => {
    if (editing) {
      if (!next) onClose?.();
      return;
    }
    // Reset HERE, not only in the dialog's onOpenChange. Radix fires that for
    // its own interactions (Esc, overlay, X) but not for our own Cancel button
    // or trigger — and in create mode this component never unmounts, so the
    // last attempt's red "already exists" banner and its filled-in port came
    // back the next time the dialog was opened.
    if (!next) resetForm();
    setSelfOpen(next);
  };

  const services = useMemo(() => presets.filter((p) => p.port != null), [presets]);

  const form = useForm({
    resolver: zodResolver(createFirewallRuleSchema),
    defaultValues: valuesFrom(rule),
    // Not on blur: leaving a field you haven't filled in yet is not a mistake,
    // and clicking "Only my IP" blurred the empty port field and reported
    // "Enter a port" for an action that had nothing to do with the port.
    // Errors appear when you try to save, then correct themselves as you type.
    mode: "onSubmit",
    reValidateMode: "onChange",
  });

  const values = useWatch({ control: form.control });
  const blocking = values.action === "deny";

  // The last name this dialog filled in by itself. Anything the user typed is
  // theirs and is never overwritten — but a name we put there is fair game to
  // replace when they pick a different service, so switching HTTPS → MySQL
  // doesn't leave a rule called "HTTPS" on port 3306.
  const [autoName, setAutoName] = useState("");

  // Everything a closed dialog must forget: the values, the field errors and
  // the server's refusal banner.
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
    // Last attempt's refusal, cleared before this one. It is not bound to a
    // field, so nothing else clears it — and a stale "already exists" sitting
    // above a rule you just changed is worse than no message.
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
        // Explicit nulls: on an edit these fields are being CLEARED when empty,
        // where on a create they are simply absent.
        payload.port_to = parsed.to ?? null;
        payload.source_ip = submitted.source_ip?.trim() || null;
        payload.description = submitted.description?.trim() || null;
        await updateFirewallRule(rule.id, payload);
        toast.success(t("edit.saved"));
      } else {
        await createFirewallRule(payload);
        toast.success(t("add.created"));
      }
      setOpen(false);
      setAutoName("");
      form.reset(editing ? valuesFrom(rule) : DEFAULTS);
      router.refresh();
    } catch (error) {
      // 422 is usually "you already have this rule" — that belongs on the form,
      // not in a toast that vanishes while the form sits there. It is not a
      // port problem either: the server compares port AND protocol AND action
      // AND source, so pinning it under Port would name the wrong culprit.
      handleValidationError(error, form, { formError: true });
    }
  }

  const { isSubmitting } = form.formState;
  const portError = form.formState.errors.ports?.message;
  const sourceError = form.formState.errors.source_ip?.message;
  const nameError = form.formState.errors.description?.message;
  const serverError = form.formState.errors.root?.server?.message;

  const parsed = parsePorts(values.ports);
  // Named here rather than only after the server says 422: the form already has
  // the rule list, so it can warn while the button is still unpressed.
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
      {/* Plain <Button>, same as every other "add" in the app (cron jobs,
          users, system users). It was outline+sm, which reads as secondary. */}
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
          // setOpen resets on its own now, so Cancel, Esc, the overlay and the
          // X all go through the same path.
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
          {/* What the server refused, kept on screen. Above the summary because
              it is the reason the dialog is still open — everything below it is
              the rule you were trying to make. */}
          {serverError ? (
            <p
              role="alert"
              className="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2.5 text-sm leading-relaxed text-destructive"
            >
              <TriangleAlert className="mt-0.5 size-4 shrink-0" />
              {serverError}
            </p>
          ) : null}

          {/* Leads, and scrolls with everything else.
              It was `sticky`, which meant the ACTION label and the Allow/Block
              buttons slid underneath it — and the checked toggle item carries
              its own z-10, so it painted back over the top. Pinning something
              inside a short dialog buys nothing and costs a z-index fight. */}
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

          {/* A database facing the whole internet is the most expensive mistake
              available on this screen, so it is said out loud before the rule
              exists — not left for the user to work out from a port number. */}
          {risky ? (
            <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 px-3 py-2.5 text-xs leading-relaxed text-warning">
              <TriangleAlert className="mt-0.5 size-4 shrink-0" />
              {t("add.riskyExposure", { name: risky })}
            </p>
          ) : null}

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

          {/* Equal halves: two short fields side by side read as a pair, and a
              lopsided pair looks like one of them is more important than it is. */}
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="fw-ports">
                {t("add.portsLabel")}
                <RequiredMark />
              </Label>
              <Input
                id="fw-ports"
                // The first thing you came here to type. Opening a dialog and
                // then having to click before typing is a wasted step.
                autoFocus
                inputMode="numeric"
                placeholder={t("add.portsPlaceholder")}
                className="font-mono"
                aria-invalid={Boolean(portError)}
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
              <Label htmlFor="fw-protocol">{t("add.protocol")}</Label>
              <Select
                value={values.protocol}
                onValueChange={(next) => form.setValue("protocol", next)}
              >
                <SelectTrigger id="fw-protocol" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="tcp">TCP</SelectItem>
                  <SelectItem value="udp">UDP</SelectItem>
                  <SelectItem value="all">{t("add.protocolAll")}</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          {/* A shortcut for filling the port, not a question of its own — hence
              the quiet label and the small chips. */}
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
            <Label htmlFor="fw-source">{t("add.source")}</Label>
            <div className="flex flex-wrap items-center gap-2">
              <Input
                id="fw-source"
                placeholder={t("add.sourcePlaceholder")}
                className="font-mono sm:max-w-64"
                autoComplete="off"
                spellCheck={false}
                aria-invalid={Boolean(sourceError)}
                {...form.register("source_ip")}
              />
              {/* Nobody goes and looks up their own address mid-task, so the
                  choice was really "anywhere" or nothing. One click makes the
                  safe option the easy one. */}
              {yourIp ? (
                <Button
                  type="button"
                  variant="secondary"
                  className="gap-1.5"
                  onClick={() => form.setValue("source_ip", yourIp, { shouldDirty: true })}
                  disabled={values.source_ip === yourIp}
                  disabledReason={t("add.wouldLockYouOut")}
                >
                  <Crosshair className="size-4" />
                  {values.source_ip === yourIp ? t("add.onlyMyIpSet") : t("add.onlyMyIp")}
                </Button>
              ) : null}
            </div>
            <p className="text-xs leading-relaxed text-muted-foreground">
              {t("add.sourceHint")}
            </p>
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
              // The API's own limit. Stops the overrun at the keyboard rather
              // than at the server.
              maxLength={255}
              placeholder={t("add.namePlaceholder")}
              aria-invalid={Boolean(nameError)}
              {...form.register("description")}
            />
            <p className="text-xs leading-relaxed text-muted-foreground">
              {t("add.nameHint")}
            </p>
            {/* This was the only field with no error slot, so anything the
                server said about it was written into form state and rendered
                nowhere. */}
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

/** A small muted heading: it labels the group without competing with it. */
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
 * theirs through — a server message run through `t()` comes out as the key.
 */
function message(t, text) {
  const KEYS = [
    "required",
    "portShape",
    "portRange",
    "portOrder",
    "invalidSource",
    "nameTooLong",
  ];
  return KEYS.includes(text) ? t(`add.errors.${text}`) : text;
}
