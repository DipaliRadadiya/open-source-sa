import { z } from "zod";
import { isIpOrCidr } from "@/lib/validation/ip";
import { listMetaSchema } from "@/lib/schemas/list";

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
  enabled: z.boolean().optional().default(true),
  summary: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
});

export const firewallResponseSchema = z.object({
  enabled: z.boolean().nullable(),
  status_reference: z.string().nullish(),
  default_policy: z
    .object({ incoming: z.string().nullable().optional(), outgoing: z.string().nullable().optional() })
    .nullable()
    .optional(),
  rules: z.array(firewallRuleSchema).default([]),
  ssh_port: z.number().nullable().optional(),
  your_ip: z.string().nullable().optional(),
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
  risky_ports: z
    .array(
      z.object({
        port: z.number(),
        label: z.string(),
        reason: z.string().nullable().optional(),
        // null = unknown.
        installed: z.boolean().nullable().optional(),
      }),
    )
    .default([]),
  unmanaged_rules: z
    .array(
      z.object({
        key: z.string(),
        rule: z.string(),
        adoptable: z.boolean().default(false),
        reason: z.string().nullish(),
      }),
    )
    .nullish(),
});

export const firewallPresetSchema = z.object({
  key: z.string(),
  label: z.string(),
  port: z.number().nullable().optional(),
  protocol: z.string().nullable().optional(),
});

export const firewallPresetsResponseSchema = z.object({
  presets: z.array(firewallPresetSchema).default([]),
});

export const firewallRulesResponseSchema = z.object({
  rules: z.array(firewallRuleSchema).default([]),
  meta: listMetaSchema,
});

export const CUSTOM_PRESET = "custom";

export const createFirewallRuleSchema = z
  .object({
    preset: z.string().default(CUSTOM_PRESET),
    ports: z.string().min(1, { message: "required" }),
    protocol: z.enum(["all", "tcp", "udp"]),
    action: z.enum(["allow", "deny"]),
    source_ip: z.string().optional(),
    description: z.string().max(255, { message: "nameTooLong" }).optional(),
  })
  .superRefine((values, ctx) => {
    const parsed = parsePorts(values.ports);
    if (!parsed) {
      ctx.addIssue({ code: "custom", path: ["ports"], message: "portShape" });
    } else if (parsed.from < 1 || parsed.from > 65535 || (parsed.to && parsed.to > 65535)) {
      ctx.addIssue({ code: "custom", path: ["ports"], message: "portRange" });
    } else if (parsed.to && parsed.to < parsed.from) {
      ctx.addIssue({ code: "custom", path: ["ports"], message: "portOrder" });
    } else if (parsed.to && parsed.to !== parsed.from && values.protocol === "all") {
      ctx.addIssue({ code: "custom", path: ["protocol"], message: "rangeNeedsProtocol" });
    }

    const source = values.source_ip?.trim();
    if (source && !isIpOrCidr(source)) {
      ctx.addIssue({ code: "custom", path: ["source_ip"], message: "invalidSource" });
    }
  });

export function parsePorts(input) {
  const text = String(input ?? "").trim();
  if (!text) return null;
  const match = text.match(/^(\d{1,5})(?:\s*[-:]\s*(\d{1,5}))?$/);
  if (!match) return null;
  return { from: Number(match[1]), to: match[2] ? Number(match[2]) : null };
}
