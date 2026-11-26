// Fails lint when a control is disabled without saying why.
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";

const ROOTS = ["components", "app"];

const IN_FLIGHT =
  /submitting|pending|saving|busy|loading|inflight|running|working|removing|retrying|verifying|starting|opening|checking|deleting|creating|leaving|signing|stopping|testing|installing|uploading|deploying|refreshing|pulling/i;

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
    if (HAS_REASON.test(src) && src.includes("DisabledReasonProvider")) continue;
    const lines = src.split("\n");

    lines.forEach((line, i) => {
      const match = line.match(/disabled=\{([^}]*)\}/);
      if (!match) return;
      const expr = match[1].trim();
      if (!expr) return;
      // Exempt only when every operand is in-flight; isSubmitting || !isDirty is a real precondition.
      const operands = expr.split("||").map((part) => part.trim()).filter(Boolean);
      if (operands.length && operands.every((part) => IN_FLIGHT.test(part))) return;
      if (/^disabled$/.test(expr) || /^disabled\s*\|\|/.test(expr)) return;
      if (/\bpage\s*(<=|>=|===?)\s*(0|1|lastPage|pageCount\s*-\s*1)\b/.test(expr)) return;
      if (/\bconfirmDisabled\b/.test(expr)) return;

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
