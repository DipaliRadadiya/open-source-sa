import { z } from "zod";

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
