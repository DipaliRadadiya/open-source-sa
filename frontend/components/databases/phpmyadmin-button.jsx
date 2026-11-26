"use client";

import { TableProperties } from "lucide-react";
import { phpmyadminSso } from "@/lib/api/databases";
import { phpmyadminState } from "@/lib/databases/phpmyadmin-state";
import { DbToolButton } from "@/components/databases/db-tool-button";

const PHPMYADMIN = {
  name: "phpMyAdmin",
  namespace: "databases.phpmyadmin",
  type: "phpmyadmin",
  Icon: TableProperties,
  sso: phpmyadminSso,
  stateOf: phpmyadminState,
};

export function PhpmyadminButton(props) {
  return <DbToolButton tool={PHPMYADMIN} {...props} />;
}
