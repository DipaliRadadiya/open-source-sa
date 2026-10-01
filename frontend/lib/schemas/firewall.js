import { z } from "zod";
import { isIpOrCidr } from "@/lib/validation/ip";
import { listMetaSchema } from "@/lib/schemas/list";

/**
 * `summary` is the API's localized sentence for the rule ("Allow 443/tcp from
 * Anywhere"); render it rather than rebuilding one from the parts.
 *
 * `protected: true` marks a system-seeded rule (SSH, the panel's own ports),
 * which cannot be deleted while the firewall is on (lockout guard).
 */
export const firewallRuleSchema = z.object({
  id: z.union([z.number(), z.string()]),
  port_from: z.number().nullable().optional(),
  port_to: z.number().nullable().optional(),
  protocol: z.string().nullable().optional(),
  action: z.string().nullable().optional(),
  source_ip: z.string().nullable().optional(),
  description: z.string().nullable().optional(),
  origin: z.string().nullable().optional(),
  protected: z.boolean().optional(),
  // A disabled rule is kept but removed from UFW, so it can be taken out of
  // service without being lost.
  enabled: z.boolean().optional().default(true),
  summary: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
});

export const firewallResponseSchema = z.object({
  // Null when the panel could not read ufw (FirewallController): not "off".
  enabled: z.boolean().nullable(),
  status_reference: z.string().nullish(),
  default_policy: z
    .object({ incoming: z.string().nullable().optional(), outgoing: z.string().nullable().optional() })
    .nullable()
    .optional(),
  rules: z.array(firewallRuleSchema).default([]),
  // The real SSH port, provided here because a firewall-only user gets a 403
  // from Settings and would fall back to 22.
  ssh_port: z.number().nullable().optional(),
  your_ip: z.string().nullable().optional(),
  // What is actually bound on the machine. `public` matters: a socket on
  // 127.0.0.1 cannot be exposed by any rule. `program` is usually null (the
  // panel is unprivileged) and is deliberately not inferred from the port.
  listening: z
    .array(
      z.object({
        port: z.number(),
        protocol: z.string().nullable().optional(),
        address: z.string().nullable().optional(),
        public: z.boolean().nullable().optional(),
        program: z.string().nullable().optional(),
      }),
    )
    .default([]),
  // Derived from the engines on this server. `installed: false` still warns:
  // opening the port before the engine arrives is the same mistake.
  risky_ports: z
    .array(
      z.object({
        port: z.number(),
        label: z.string(),
        reason: z.string().nullable().optional(),
        // null = unknown, neither true nor false; must not fail the response.
        installed: z.boolean().nullable().optional(),
      }),
    )
    .default([]),
});

export const firewallPresetSchema = z.object({
  key: z.string(),
  label: z.string(),
  // null for `custom`: the UI shows raw port fields instead of filling them.
  port: z.number().nullable().optional(),
  protocol: z.string().nullable().optional(),
});

export const firewallPresetsResponseSchema = z.object({
  presets: z.array(firewallPresetSchema).default([]),
});

/**
 * The paginated rules endpoint skips GET /firewall's live UFW work. `meta` is
 * required: treating a page as the complete list would hide later rules.
 */
export const firewallRulesResponseSchema = z.object({
  rules: z.array(firewallRuleSchema).default([]),
  meta: listMetaSchema,
});

export const CUSTOM_PRESET = "custom";

/**
 * The add-rule form. Ports are ONE field ("443", "8000-8090"), split by
 * `parsePorts`. Blank `source_ip` means "from anywhere", not a missing value.
 */
export const createFirewallRuleSchema = z
  .object({
    preset: z.string().default(CUSTOM_PRESET),
    // "required", not "requiredField": add-rule-dialog translates a fixed list
    // of keys and passes anything else through as literal text.
    ports: z.string().min(1, { message: "required" }),
    protocol: z.enum(["all", "tcp", "udp"]),
    action: z.enum(["allow", "deny"]),
    source_ip: z.string().optional(),
    // Matches the API's `max:255`; the Name field has no server-error slot.
    description: z.string().max(255, { message: "nameTooLong" }).optional(),
  })
  .superRefine((values, ctx) => {
    const parsed = parsePorts(values.ports);
    if (!parsed) {
      ctx.addIssue({ code: "custom", path: ["ports"], message: "portShape" });
      // 1–65535 on both ends, as FirewallRule::PORT_MIN/PORT_MAX.
    } else if (parsed.from < 1 || parsed.from > 65535 || (parsed.to && parsed.to > 65535)) {
      ctx.addIssue({ code: "custom", path: ["ports"], message: "portRange" });
    } else if (parsed.to && parsed.to < parsed.from) {
      ctx.addIssue({ code: "custom", path: ["ports"], message: "portOrder" });
    } else if (parsed.to && parsed.to !== parsed.from && values.protocol === "all") {
      // The server's firewall accepts a range only with a single protocol.
      ctx.addIssue({ code: "custom", path: ["protocol"], message: "rangeNeedsProtocol" });
    }

    const source = values.source_ip?.trim();
    // Blank is meaningful ("anywhere"); only a non-empty value has to parse.
    if (source && !isIpOrCidr(source)) {
      ctx.addIssue({ code: "custom", path: ["source_ip"], message: "invalidSource" });
    }
  });

/**
 * "443" | "8000-8090" | "8000:8090" | "8000 - 8090" → `{from, to}`. Null for
 * anything else, so "unparseable" and "out of range" get different messages.
 */
export function parsePorts(input) {
  const text = String(input ?? "").trim();
  if (!text) return null;
  const match = text.match(/^(\d{1,5})(?:\s*[-:]\s*(\d{1,5}))?$/);
  if (!match) return null;
  return { from: Number(match[1]), to: match[2] ? Number(match[2]) : null };
}
