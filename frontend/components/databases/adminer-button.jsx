"use client";

import { PanelsTopLeft } from "lucide-react";
import { adminerSso } from "@/lib/api/databases";
import { adminerState } from "@/lib/databases/adminer-state";
import { DbToolButton } from "@/components/databases/db-tool-button";

const ADMINER = {
  name: "Adminer",
  namespace: "databases.adminer",
  type: "adminer",
  Icon: PanelsTopLeft,
  sso: adminerSso,
  stateOf: adminerState,
};

export function AdminerButton(props) {
  return <DbToolButton tool={ADMINER} {...props} />;
}
