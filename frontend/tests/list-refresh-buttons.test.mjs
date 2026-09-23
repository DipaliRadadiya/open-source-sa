import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

// Every list that changes without the reader doing anything — a new ban, a
// backup finishing, a request blocked — has the same refresh button the other
// tables have. These twelve were the ones without one (2026-09-23).
const LISTS = {
  "Backups overview": "components/backups/coverage-card.jsx",
  "Backup history": "components/backups/backups-history.jsx",
  "Restores": "components/backups/restores-list.jsx",
  "Fail2ban banned IPs": "components/fail2ban/banned-card.jsx",
  "Files trash": "components/applications/files/trash-panel.jsx",
  "Web firewall blocked requests": "components/applications/firewall/detect-log-card.jsx",
  "Bot traffic": "components/applications/bot-blocker/bot-traffic-card.jsx",
  "Deploy history": "components/applications/deployment/deploy-history-card.jsx",
  "Clone history": "components/applications/clone/clone-panel.jsx",
  "Database tables": "components/databases/database-tables.jsx",
  "Database exports": "components/databases/database-exports.jsx",
  "Disk cleaner runs": "components/disk-cleaner/runs-card.jsx",
};

test("every list that changes on its own has a refresh button", () => {
  for (const [name, file] of Object.entries(LISTS)) {
    const src = read(file);
    assert.match(src, /import \{ RefreshButton \} from "@\/components\/data-table\/refresh-button";/, `${name}: import`);
    assert.match(src, /<RefreshButton\b/, `${name}: rendered`);
  }
});

test("the refresh button can sit in a server-rendered card", () => {
  // Three of the cards above are server components; the button holds a
  // transition, so it has to be its own client boundary.
  assert.match(read("components/data-table/refresh-button.jsx"), /^"use client";/);
});
