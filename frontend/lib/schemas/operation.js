import { z } from "zod";

// GET /operations (RC-20). `state` is the one word every kind shares; the kind's own
// `status` stays for display. Unknown kinds and states are kept, not dropped, so a
// job type added later still shows up.
export const operationSchema = z.object({
  kind: z.string(),
  id: z.union([z.number(), z.string()]),
  state: z.string(),
  status: z.string().nullish(),
  subject: z
    .object({
      type: z.string(),
      id: z.union([z.number(), z.string()]).nullish(),
      name: z.string().nullish(),
    })
    .nullish(),
  step: z.string().nullish(),
  started_at: z.string().nullish(),
  finished_at: z.string().nullish(),
  finished_at_human: z.string().nullish(),
});

export const operationsResponseSchema = z.object({
  operations: z.array(operationSchema),
  meta: z.object({ running: z.number().default(0) }).partial().default({}),
});

export const isActive = (operation) => operation.state === "waiting" || operation.state === "running";
