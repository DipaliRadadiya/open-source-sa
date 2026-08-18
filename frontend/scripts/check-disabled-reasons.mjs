/**
 * Fails the build when a control is disabled for a reason the user is never
 * told.
 *
 * Why this exists: a disabled button explains nothing by itself. Until
 * 2026-08-18 every one of them fell back to "This control is unavailable right
 * now.", which is worse than silence — it trains people to stop reading
 * tooltips. That fallback is gone, so a control with no reason now says
 * nothing at all, and nothing in the build would notice.
 *
 * Only two kinds of `disabled` are exempt:
 *   - mid-action (`isSubmitting`, `pending`, `busy`…). The spinner is the
 *     answer; "why is this disabled" is not the question being asked.
 *   - literal `disabled` / `disabled || x` pass-through props, where the parent
 *     owns the reason and supplies it through DisabledReasonProvider.
 *
 * Everything else is a precondition or a permission, and has to say so.
 */
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";

const ROOTS = ["components", "app"];

// Disabled because something is in flight — no explanation wanted.
const IN_FLIGHT =
  /submitting|pending|saving|busy|loading|inflight|running|working|removing|retrying|verifying|starting|opening|checking|deleting|creating|leaving|stopping|testing|installing|uploading|deploying|refreshing/i;

// A reason is present if the control carries one, or something nearby does.
const HAS_REASON =
  /disabledReason|ReasonTooltip|TooltipContent|MenuItemHint|DisabledReasonProvider/;

function walk(dir, out = []) {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) walk(path, out);
    else if (path.endsWith(".jsx")) out.push(path);
  }
  return out;
}

const problems = [];

for (const root of ROOTS) {
  for (const file of walk(root)) {
    const src = readFileSync(file, "utf8");
    // A provider anywhere in the file covers every control beneath it.
    if (HAS_REASON.test(src) && src.includes("DisabledReasonProvider")) continue;
    const lines = src.split("\n");

    lines.forEach((line, i) => {
      const match = line.match(/disabled=\{([^}]*)\}/);
      if (!match) return;
      const expr = match[1].trim();
      if (!expr) return;
      if (IN_FLIGHT.test(expr)) return;
      if (/^disabled$/.test(expr) || /^disabled\s*\|\|/.test(expr)) return;
      // First/last page on a pager. The control sits between two arrows and a
      // page number; a sentence saying "you are on the first page" is noise.
      if (/^page\s*===?\s*0$/.test(expr) || /pageCount\s*-\s*1$/.test(expr)) return;

      // The whole enclosing component, not a fixed window: a control can be
      // declared near the top and wrapped in its tooltip forty lines later.
      let start = i;
      while (start > 0 && !/^(export )?function /.test(lines[start])) start--;
      let end = i + 1;
      while (end < lines.length && !/^(export )?function /.test(lines[end])) end++;
      const around = lines.slice(start, end).join("\n");
      if (HAS_REASON.test(around)) return;

      problems.push(`${file}:${i + 1}  disabled={${expr}} has no reason`);
    });
  }
}

if (problems.length) {
  console.error(`\ndisabled-reason check failed — ${problems.length} control(s):\n`);
  for (const p of problems) console.error("  " + p);
  console.error(
    "\nGive it `disabledReason`, wrap it in <ReasonTooltip>, or put the section\n" +
      "inside <DisabledReasonProvider>. Say why it is off and what to do about it.\n",
  );
  process.exit(1);
}

console.log("disabled reasons ok — every disabled control explains itself");
