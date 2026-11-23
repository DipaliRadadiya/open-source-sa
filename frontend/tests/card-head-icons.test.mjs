import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

const read = (p) => fs.readFileSync(p, "utf8");

// 23 Nov (Krishna): every card heading carries the Account-style icon chip, so headings
// stand apart from content and pages scan quickly.
test("one chip for every card heading", () => {
  const chip = read("components/ui/head-icon.jsx");
  assert.match(chip, /size-9 shrink-0 items-center justify-center rounded-\[2px\] border/);
  const card = read("components/ui/card.jsx");
  assert.match(card, /icon && "flex flex-nowrap items-center gap-3"/);
  assert.match(card, /<HeadIcon icon=\{icon\} tone=\{iconTone\} \/>/);
  // A description under an icon heading starts under the title's text.
  assert.match(card, /group-has-\[\[data-slot=head-icon\]\]\/card-header:pl-12/);
  // The older database chip is the same chip now.
  assert.match(read("components/ui/card-icon.jsx"), /<HeadIcon /);
});

test("the main cards carry their icon", () => {
  const cases = {
    "components/dashboard/live-metrics-section.jsx": /<CardTitle icon=\{Activity\}/,
    "components/dashboard/server-info-card.jsx": /<CardTitle icon=\{Server\}/,
    "components/dashboard/processes-card.jsx": /<CardTitle icon=\{Cpu\}/,
    "components/applications/domains-card.jsx": /<CardTitle icon=\{Globe\}/,
    "components/applications/database-card.jsx": /<CardTitle icon=\{Database\}/,
    "components/applications/backup-card.jsx": /<CardTitle icon=\{Archive\}/,
    "components/applications/protection-card.jsx": /<CardTitle icon=\{Shield\}/,
    "components/fail2ban/jails-card.jsx": /<CardTitle icon=\{Lock\}/,
    "components/firewall/rules-card.jsx": /<CardTitle icon=\{BrickWall\}/,
    "components/integrations/storage/destinations-card.jsx": /<HeadIcon icon=\{HardDrive\} \/>/,
    "components/integrations/git/accounts-card.jsx": /<HeadIcon icon=\{GitBranch\} \/>/,
    "components/services/services-panel.jsx": /icon=\{Blocks\}/,
    "components/dashboard/live-chart-card.jsx": /<HeadIcon icon=\{icon\} \/>/,
  };
  for (const [file, pattern] of Object.entries(cases)) assert.match(read(file), pattern, file);
});
