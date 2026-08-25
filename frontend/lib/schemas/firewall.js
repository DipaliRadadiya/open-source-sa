import { z } from "zod";
import { isIpOrCidr } from "@/lib/validation/ip";
import { listMetaSchema } from "@/lib/schemas/list";

/**
 * `summary` is the API's own plain-English sentence for the rule ("Allow 443/tcp
 * from Anywhere"), localized server-side. The UI leads with it rather than
 * rebuilding one from the parts — two renderings of the same fact drift.
 *
 * `protected: true` marks a system-seeded rule (SSH, the panel's own ports).
 * Those cannot be deleted while the firewall is on, which is a lockout guard,
 * not a limitation.
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
  // A disabled rule is kept but removed from UFW — the way to take a rule out of
  // service without losing it (and, for a deny rule, without letting traffic
  // through while it's gone).
  enabled: z.boolean().optional().default(true),
  summary: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
});

export const firewallResponseSchema = z.object({
  enabled: z.boolean(),
  default_policy: z
    .object({ incoming: z.string().nullable().optional(), outgoing: z.string().nullable().optional() })
    .nullable()
    .optional(),
  rules: z.array(firewallRuleSchema).default([]),
  // The port SSH is really on, answered here so this page doesn't have to ask
  // Settings — a firewall-only user gets a 403 there and would fall back to 22.
  ssh_port: z.number().nullable().optional(),
  your_ip: z.string().nullable().optional(),
  // What is actually bound on the machine. `public` is the field that matters:
  // a socket on 127.0.0.1 cannot be exposed by any rule, so it is never
  // flagged. `program` is null for most entries — the panel is unprivileged
  // and the kernel only names a socket's process to its owner — and is
  // deliberately not inferred from the port.
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
  // Derived from the engines installed on THIS server, which is why it replaced
  // the list the frontend used to carry. `installed: false` still warns: opening
  // the port before the engine arrives is the same mistake, earlier.
  risky_ports: z
    .array(
      z.object({
        port: z.number(),
        label: z.string(),
        reason: z.string().nullable().optional(),
        // null = unknown (not a package the server tracks), which is neither
        // true nor false and must not fail the whole response.
        installed: z.boolean().nullable().optional(),
      }),
    )
    .default([]),
});

export const firewallPresetSchema = z.object({
  key: z.string(),
  label: z.string(),
  // null for `custom` — the UI shows raw port fields instead of filling them.
  port: z.number().nullable().optional(),
  protocol: z.string().nullable().optional(),
});

export const firewallPresetsResponseSchema = z.object({
  presets: z.array(firewallPresetSchema).default([]),
});

/**
 * The paginated rules endpoint deliberately avoids the live UFW/status work
 * performed by GET /firewall. Its rows are the same resource shape, but its
 * meta is required: silently treating a paged response as a complete list
 * would make rules beyond page one unreachable.
 */
export const firewallRulesResponseSchema = z.object({
  rules: z.array(firewallRuleSchema).default([]),
  meta: listMetaSchema,
});

export const CUSTOM_PRESET = "custom";

/**
 * The add-rule form.
 *
 * Ports are ONE field, not two. "8000-8090" is how a person writes a range, and
 * the research calls this a forgiving format: accept the shapes people type and
 * normalise on the way out, rather than splitting the question into two inputs
 * because the API happens to take two numbers. `parsePorts` does the splitting.
 *
 * Blank `source_ip` means "from anywhere" — an empty string is a valid answer
 * here, so it must not be treated as a missing required field.
 */
export const createFirewallRuleSchema = z
  .object({
    preset: z.string().default(CUSTOM_PRESET),
    ports: z.string().min(1, { message: "requiredField" }),
    protocol: z.enum(["all", "tcp", "udp"]),
    action: z.enum(["allow", "deny"]),
    source_ip: z.string().optional(),
    // Bounded to match the API's `max:255`. Unbounded, a longer name sailed
    // through, 422'd on `description` — a real form field, so the handler put
    // the message on it — and the Name field renders no error, so it landed
    // nowhere. The button spun and the dialog just sat there.
    description: z.string().max(255, { message: "nameTooLong" }).optional(),
  })
  .superRefine((values, ctx) => {
    const parsed = parsePorts(values.ports);
    if (!parsed) {
      ctx.addIssue({ code: "custom", path: ["ports"], message: "portShape" });
      // Both ends capped at 65534, which is what the create endpoint accepts
      // (`between:1,65534` on port_from AND port_to). The end used to be
      // allowed up to 65535: the client passed it, the server refused it on
      // `port_to` — a field name that does not exist on this screen — and the
      // matching message claimed "1 to 65535" while rejecting 65535.
    } else if (parsed.from < 1 || parsed.from > 65534 || (parsed.to && parsed.to > 65534)) {
      ctx.addIssue({ code: "custom", path: ["ports"], message: "portRange" });
    } else if (parsed.to && parsed.to < parsed.from) {
      ctx.addIssue({ code: "custom", path: ["ports"], message: "portOrder" });
    }

    const source = values.source_ip?.trim();
    // Blank is meaningful ("anywhere"); only a non-empty value has to parse.
    if (source && !isIpOrCidr(source)) {
      ctx.addIssue({ code: "custom", path: ["source_ip"], message: "invalidSource" });
    }
  });

/**
 * "443" | "8000-8090" | "8000:8090" | "8000 - 8090" → `{from, to}`.
 *
 * Returns null for anything that isn't one of those, so the caller can tell
 * "unparseable" apart from "out of range" and say which.
 */
export function parsePorts(input) {
  const text = String(input ?? "").trim();
  if (!text) return null;
  const match = text.match(/^(\d{1,5})(?:\s*[-:]\s*(\d{1,5}))?$/);
  if (!match) return null;
  return { from: Number(match[1]), to: match[2] ? Number(match[2]) : null };
}
