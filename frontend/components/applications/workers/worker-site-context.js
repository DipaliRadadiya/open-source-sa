"use client";

import { createContext, useContext } from "react";

const WorkerSiteContext = createContext({ appRoot: "" });

export const WorkerSiteProvider = WorkerSiteContext.Provider;

export function useWorkerSite() {
  return useContext(WorkerSiteContext);
}
