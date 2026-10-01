"use client";

import { createContext, useContext } from "react";

/**
 * The application's own folder, for the worker dialogs' path rules. A context
 * so the rows hosting the edit dialog need not pass it through.
 */
const WorkerSiteContext = createContext({ appRoot: "" });

export const WorkerSiteProvider = WorkerSiteContext.Provider;

export function useWorkerSite() {
  return useContext(WorkerSiteContext);
}
