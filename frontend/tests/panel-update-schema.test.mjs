import assert from "node:assert/strict";
import test from "node:test";
import { panelUpdateRunSchema } from "../lib/schemas/panel-update.js";

// Exactly what POST /api/admin/panel-update returns for a freshly queued run,
// captured from a real server. `rolled_back` is null because the column is
// nullable until the run either rolls back or does not.
const freshlyQueued = {
  id: 10,
  status: "pending",
  status_title: "Starting",
  current_step: null,
  current_step_title: null,
  step_number: null,
  total_steps: 16,
  from_version: "1.0.6",
  to_version: "1.0.7",
  from_commit: "e8a7efcf14c8a0b98653be3ca93369b659ea7844",
  to_commit: null,
  reason: null,
  reason_title: null,
  rolled_back: null,
  reference: null,
  started_at: "22-08-2026 13:02:06",
  started_at_human: "0 seconds ago",
  finished_at: null,
  finished_at_human: null,
};

test("a freshly queued run parses", () => {
  // `rolled_back: z.boolean().default(false)` threw here: Zod's default fills
  // `undefined`, not `null`. startPanelUpdate parses the 202 with this schema,
  // so a *successful* start raised a ZodError — which carries no response,
  // so the UI fell back to "Couldn't start the update" while the update ran.
  const result = panelUpdateRunSchema.safeParse(freshlyQueued);

  assert.ok(result.success, result.success ? "" : result.error?.issues?.[0]?.message);
  assert.equal(result.data.rolled_back, false);
});

test("every poll during a run parses", () => {
  // The same schema backs fetchPanelUpdateRun, so the identical throw stopped
  // the progress bar advancing — the user saw a failure, then nothing at all.
  const midRun = { ...freshlyQueued, status: "running", current_step: "frontend_build", step_number: 12 };

  assert.ok(panelUpdateRunSchema.safeParse(midRun).success);
});

test("a real rollback is still reported as one", () => {
  const rolledBack = { ...freshlyQueued, status: "failed", reason: "health_check", rolled_back: true };
  const result = panelUpdateRunSchema.safeParse(rolledBack);

  assert.ok(result.success);
  assert.equal(result.data.rolled_back, true);
});

test("sanitized update output survives parsing and remains optional for old releases", () => {
  const withOutput = panelUpdateRunSchema.parse({
    ...freshlyQueued,
    output: "Health check attempt 1/30 failed",
    output_truncated: true,
  });
  const withoutOutput = panelUpdateRunSchema.parse(freshlyQueued);

  assert.equal(withOutput.output, "Health check attempt 1/30 failed");
  assert.equal(withOutput.output_truncated, true);
  assert.equal(withoutOutput.output, "");
  assert.equal(withoutOutput.output_truncated, false);
});
