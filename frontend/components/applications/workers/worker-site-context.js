"use client";

import { createContext, useContext } from "react";

/**
 * The application's own folder, for the worker dialogs' path rules. A context
 * rather than a prop: the edit dialog sits inside table and card rows that
 * have no other reason to know it.
 */
const WorkerSiteContext = createContext({ appRoot: "" });

export const WorkerSiteProvider = WorkerSiteContext.Provider;

export function useWorkerSite() {
  return useContext(WorkerSiteContext);
}
