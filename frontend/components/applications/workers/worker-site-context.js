"use client";

import { createContext, useContext } from "react";

// The application's own folder, for the worker dialogs' path rules.
const WorkerSiteContext = createContext({ appRoot: "" });

export const WorkerSiteProvider = WorkerSiteContext.Provider;

export function useWorkerSite() {
  return useContext(WorkerSiteContext);
}
