/**
 * Fails lint when a control is disabled without telling the user why. Exempt: mid-action
 * states (every operand in-flight, e.g. `isSubmitting`, `pending`), and pass-through
 * `disabled` props whose parent supplies the reason via DisabledReasonProvider.
 */
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";

const ROOTS = ["components", "app"];

// Disabled because something is in flight — no explanation wanted.
// `signing` covers signing out and signing in, which are waits like any other;
// the list already had `leaving` for the impersonation exit sitting next to it.
const IN_FLIGHT =
  /submitting|pending|saving|busy|loading|inflight|running|working|removing|retrying|verifying|starting|opening|checking|deleting|creating|leaving|signing|stopping|testing|installing|uploading|deploying|refreshing|pulling/i;

// A reason is present if the control carries one, or something nearby does.
// IconTooltip counts because it hands `reason` straight to ReasonTooltip — the
// same guarantee, reached through the component that also labels the icon.
const HAS_REASON =
  /disabledReason|ReasonTooltip|IconTooltip|TooltipContent|MenuItemHint|DisabledReasonProvider/;

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
      // Only exempt when EVERY operand is in-flight. `isSubmitting || !isDirty`
      // used to pass on its first operand alone, which is how three Save
      // buttons ended up disabled by "no changes yet" and never saying so —
      // the exact class of silence this script exists to prevent.
      const operands = expr.split("||").map((part) => part.trim()).filter(Boolean);
      if (operands.length && operands.every((part) => IN_FLIGHT.test(part))) return;
      if (/^disabled$/.test(expr) || /^disabled\s*\|\|/.test(expr)) return;
      // First/last page on a pager. The control sits between two arrows and a
      // page number; a sentence saying "you are on the first page" is noise.
      if (/\bpage\s*(<=|>=|===?)\s*(0|1|lastPage|pageCount\s*-\s*1)\b/.test(expr)) return;
      // A pass-through the caller owns, same as bare `disabled`: the reason
      // lives with whoever decided to switch it off.
      if (/\bconfirmDisabled\b/.test(expr)) return;

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
