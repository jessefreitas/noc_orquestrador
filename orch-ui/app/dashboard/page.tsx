"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { ACCESS_TOKEN_KEY, USER_EMAIL_KEY, clearSession } from "@/lib/auth";
import styles from "./dashboard.module.css";

const menu = ["Colaboradores", "APIs Empresas", "Servidores"];
const roleHints = "admin,operator,viewer";
const providerOptions = [
  "hetzner",
  "cloudflare",
  "n8n",
  "portainer",
  "openai",
  "openrouter",
  "postgres",
  "mega_chatwoot",
  "custom"
];

type MeResponse = {
  email: string;
  roles: string[];
  areas: string[];
};

type Area = { id: number; name: string };
type UserRow = { id: number; email: string; status: string; roles: string[]; areas: string[] };
type Company = { id: number; name: string; status: string; created_at: string };
type ApiCredential = {
  id: number;
  company_id: number;
  provider: string;
  label: string;
  base_url: string | null;
  account_id: string | null;
  metadata_json: Record<string, unknown>;
  secret_present: boolean;
};
type HetznerServer = {
  id: number;
  company_id: number;
  credential_id: number | null;
  external_id: string;
  name: string;
  datacenter: string | null;
  ipv4: string | null;
  labels_json: Record<string, unknown>;
  status: string;
  allow_backup: boolean;
  allow_snapshot: boolean;
  created_at: string;
  updated_at: string;
};
type HetznerPolicy = {
  id: number;
  server_id: number;
  service_type: "backup" | "snapshot";
  enabled: boolean;
  require_confirmation: boolean;
  schedule_mode: "manual" | "interval";
  interval_minutes: number | null;
  retention_days: number | null;
  retention_count: number | null;
  last_run_at: string | null;
  next_run_at: string | null;
  last_status: string | null;
  last_error: string | null;
};
type HetznerStatus = {
  server_id: number;
  server_name: string;
  service_type: "backup" | "snapshot";
  status: "ok" | "atraso" | "falha" | "sem_politica" | "pausado";
  details: string;
  next_run_at: string | null;
  last_run_at: string | null;
};
type HetznerLog = {
  id: number;
  server_id: number;
  service_type: "backup" | "snapshot";
  action: string;
  status: string;
  message: string;
  created_by: number | null;
  created_at: string;
};
type Runbook = {
  name: string;
  version: string;
  category: string;
  enabled: boolean;
  schema_json: Record<string, unknown>;
};

type UserDraft = {
  rolesText: string;
  areasText: string;
  status: string;
  password: string;
  saving: boolean;
  message: string;
  error: string;
};

function normalizeCsv(input: string): string[] {
  return Array.from(new Set(input.split(",").map((part) => part.trim().toLowerCase()).filter(Boolean)));
}

function parseMetadata(input: string): Record<string, unknown> {
  const trimmed = input.trim();
  if (!trimmed) return {};
  const parsed = JSON.parse(trimmed);
  if (!parsed || Array.isArray(parsed) || typeof parsed !== "object") throw new Error("metadata_json invalido");
  return parsed as Record<string, unknown>;
}

function metricFromLabels(labels: Record<string, unknown>, keys: string[]): string {
  for (const key of keys) {
    const value = labels[key];
    if (typeof value === "number") return String(value);
    if (typeof value === "string" && value.trim()) return value.trim();
  }
  return "n/d";
}

function toDateSafe(value: string | null): Date | null {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function formatDateTime(value: string | null): string {
  const parsed = toDateSafe(value);
  if (!parsed) return "-";
  return parsed.toLocaleString("pt-BR");
}

export default function DashboardPage() {
  const router = useRouter();
  const [isReady, setIsReady] = useState(false);
  const [activeView, setActiveView] = useState<"colaboradores" | "apis" | "hetzner">("colaboradores");
  const [hetznerSection, setHetznerSection] = useState<"cadastro" | "servicos" | "alertas" | "logs">("cadastro");
  const [serverDetailTab, setServerDetailTab] = useState<"dashboard" | "controles" | "alertas" | "logs">("dashboard");
  const [serverSearch, setServerSearch] = useState("");
  const [serverFilter, setServerFilter] = useState<"all" | "healthy" | "alerting" | "maintenance">("all");
  const [userEmail, setUserEmail] = useState("admin@omniforge.com.br");
  const [myRoles, setMyRoles] = useState<string[]>([]);
  const [myAreas, setMyAreas] = useState<string[]>([]);

  const [areas, setAreas] = useState<Area[]>([]);
  const [users, setUsers] = useState<UserRow[]>([]);
  const [userDrafts, setUserDrafts] = useState<Record<number, UserDraft>>({});
  const [loadingUsers, setLoadingUsers] = useState(false);
  const [collabError, setCollabError] = useState("");
  const [collabMessage, setCollabMessage] = useState("");

  const [newEmail, setNewEmail] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [newRolesText, setNewRolesText] = useState("viewer");
  const [newAreasText, setNewAreasText] = useState("general");
  const [newStatus, setNewStatus] = useState("active");
  const [creatingUser, setCreatingUser] = useState(false);

  const [companies, setCompanies] = useState<Company[]>([]);
  const [selectedCompanyId, setSelectedCompanyId] = useState<number | null>(null);
  const [credentials, setCredentials] = useState<ApiCredential[]>([]);
  const [loadingApis, setLoadingApis] = useState(false);
  const [apiError, setApiError] = useState("");
  const [apiMessage, setApiMessage] = useState("");

  const [companyName, setCompanyName] = useState("");
  const [companyStatus, setCompanyStatus] = useState("active");
  const [creatingCompany, setCreatingCompany] = useState(false);

  const [provider, setProvider] = useState("hetzner");
  const [label, setLabel] = useState("");
  const [baseUrl, setBaseUrl] = useState("");
  const [accountId, setAccountId] = useState("");
  const [secretValue, setSecretValue] = useState("");
  const [metadataText, setMetadataText] = useState("{}");
  const [creatingCredential, setCreatingCredential] = useState(false);
  const [editingCredentialId, setEditingCredentialId] = useState<number | null>(null);
  const [deletingCredentialId, setDeletingCredentialId] = useState<number | null>(null);
  const [hetznerServers, setHetznerServers] = useState<HetznerServer[]>([]);
  const [hetznerPolicies, setHetznerPolicies] = useState<HetznerPolicy[]>([]);
  const [hetznerStatus, setHetznerStatus] = useState<HetznerStatus[]>([]);
  const [hetznerAlerts, setHetznerAlerts] = useState<HetznerStatus[]>([]);
  const [hetznerLogs, setHetznerLogs] = useState<HetznerLog[]>([]);
  const [loadingHetzner, setLoadingHetzner] = useState(false);
  const [hetznerError, setHetznerError] = useState("");
  const [hetznerMessage, setHetznerMessage] = useState("");

  const [newServerExternalId, setNewServerExternalId] = useState("");
  const [newServerName, setNewServerName] = useState("");
  const [newServerDatacenter, setNewServerDatacenter] = useState("");
  const [newServerIpv4, setNewServerIpv4] = useState("");
  const [newServerLabels, setNewServerLabels] = useState("{}");
  const [creatingHetznerServer, setCreatingHetznerServer] = useState(false);

  const [selectedHetznerCredentialId, setSelectedHetznerCredentialId] = useState<number | null>(null);
  const [importingHetzner, setImportingHetzner] = useState(false);

  const [selectedHetznerServerId, setSelectedHetznerServerId] = useState<number | null>(null);
  const [policyEnabled, setPolicyEnabled] = useState(true);
  const [policyRequireConfirm, setPolicyRequireConfirm] = useState(true);
  const [policyScheduleMode, setPolicyScheduleMode] = useState<"manual" | "interval">("manual");
  const [policyIntervalMinutes, setPolicyIntervalMinutes] = useState("60");
  const [policyRetentionDays, setPolicyRetentionDays] = useState("7");
  const [policyRetentionCount, setPolicyRetentionCount] = useState("7");
  const [updatingServerId, setUpdatingServerId] = useState<number | null>(null);
  const [runbooks, setRunbooks] = useState<Runbook[]>([]);
  const [selectedRunbookName, setSelectedRunbookName] = useState("");
  const [executingRunbook, setExecutingRunbook] = useState(false);

  const isAdmin = myRoles.includes("admin");
  const hetznerCredentials = credentials.filter((item) => item.provider === "hetzner");
  const selectedServer = useMemo(
    () => hetznerServers.find((server) => server.id === selectedHetznerServerId) ?? null,
    [hetznerServers, selectedHetznerServerId]
  );
  const selectedServerStatuses = useMemo(
    () => (selectedServer ? hetznerStatus.filter((item) => item.server_id === selectedServer.id) : []),
    [selectedServer, hetznerStatus]
  );
  const selectedServerAlerts = useMemo(
    () => (selectedServer ? hetznerAlerts.filter((item) => item.server_id === selectedServer.id) : []),
    [selectedServer, hetznerAlerts]
  );
  const selectedServerLogs = useMemo(
    () => (selectedServer ? hetznerLogs.filter((log) => log.server_id === selectedServer.id) : []),
    [selectedServer, hetznerLogs]
  );
  const credentialLabelById = useMemo(() => {
    const map = new Map<number, string>();
    for (const row of hetznerCredentials) map.set(row.id, row.label);
    return map;
  }, [hetznerCredentials]);

  const onLogout = () => {
    clearSession();
    router.replace("/login");
  };

  const apiRequest = async (path: string, init?: RequestInit): Promise<Response> => {
    const token = localStorage.getItem(ACCESS_TOKEN_KEY) ?? "";
    const headers = new Headers(init?.headers ?? {});
    headers.set("Authorization", `Bearer ${token}`);
    if (init?.body && !headers.get("Content-Type")) headers.set("Content-Type", "application/json");
    return fetch(path, { ...init, headers });
  };

  const loadCollaborators = async () => {
    if (!isAdmin) return;
    setLoadingUsers(true);
    try {
      const [areasResponse, usersResponse] = await Promise.all([apiRequest("/v1/areas"), apiRequest("/v1/users")]);
      if (!areasResponse.ok || !usersResponse.ok) {
        setCollabError("Nao foi possivel carregar colaboradores.");
        return;
      }
      const loadedAreas = (await areasResponse.json()) as Area[];
      const loadedUsers = (await usersResponse.json()) as UserRow[];
      setAreas(loadedAreas);
      setUsers(loadedUsers);

      const drafts: Record<number, UserDraft> = {};
      for (const user of loadedUsers) {
        drafts[user.id] = {
          rolesText: user.roles.join(","),
          areasText: user.areas.join(","),
          status: user.status,
          password: "",
          saving: false,
          message: "",
          error: ""
        };
      }
      setUserDrafts(drafts);
    } catch {
      setCollabError("Falha de conexao ao carregar colaboradores.");
    } finally {
      setLoadingUsers(false);
    }
  };

  const loadCompanies = async () => {
    if (!isAdmin) return;
    setLoadingApis(true);
    try {
      const response = await apiRequest("/v1/companies");
      if (!response.ok) {
        setApiError("Nao foi possivel carregar empresas.");
        return;
      }
      const rows = (await response.json()) as Company[];
      setCompanies(rows);
      if (rows.length && !rows.some((row) => row.id === selectedCompanyId)) setSelectedCompanyId(rows[0].id);
      if (!rows.length) setSelectedCompanyId(null);
    } catch {
      setApiError("Falha de conexao ao carregar empresas.");
    } finally {
      setLoadingApis(false);
    }
  };

  const loadRunbooks = async () => {
    try {
      const response = await apiRequest("/v1/runbooks");
      if (!response.ok) return;
      const rows = (await response.json()) as Runbook[];
      setRunbooks(rows);
      if (!selectedRunbookName && rows.length) setSelectedRunbookName(rows[0].name);
    } catch {
      // noop: runbook list is auxiliary to server controls
    }
  };

  const loadCredentials = async (companyId: number) => {
    setLoadingApis(true);
    try {
      const response = await apiRequest(`/v1/companies/${companyId}/api-credentials`);
      if (!response.ok) {
        setApiError("Nao foi possivel carregar credenciais.");
        return;
      }
      const rows = (await response.json()) as ApiCredential[];
      setCredentials(rows);
    } catch {
      setApiError("Falha de conexao ao carregar credenciais.");
    } finally {
      setLoadingApis(false);
    }
  };

  const loadHetznerData = async (companyId: number) => {
    setLoadingHetzner(true);
    setHetznerError("");
    try {
      const [serversResp, statusResp, alertsResp, logsResp] = await Promise.all([
        apiRequest(`/v1/companies/${companyId}/hetzner/servers`),
        apiRequest(`/v1/companies/${companyId}/hetzner/status`),
        apiRequest(`/v1/companies/${companyId}/hetzner/alerts`),
        apiRequest(`/v1/companies/${companyId}/hetzner/logs`)
      ]);
      if (!serversResp.ok || !statusResp.ok || !alertsResp.ok || !logsResp.ok) {
        setHetznerError("Nao foi possivel carregar dados Hetzner.");
        return;
      }
      const loadedServers = (await serversResp.json()) as HetznerServer[];
      const loadedStatus = (await statusResp.json()) as HetznerStatus[];
      const loadedAlerts = (await alertsResp.json()) as HetznerStatus[];
      const loadedLogs = (await logsResp.json()) as HetznerLog[];

      setHetznerServers(loadedServers);
      setHetznerStatus(loadedStatus);
      setHetznerAlerts(loadedAlerts);
      setHetznerLogs(loadedLogs);

      if (loadedServers.length && !loadedServers.some((row) => row.id === selectedHetznerServerId)) {
        setSelectedHetznerServerId(loadedServers[0].id);
      }
      if (!loadedServers.length) setSelectedHetznerServerId(null);
    } catch {
      setHetznerError("Falha de conexao ao carregar Hetzner.");
    } finally {
      setLoadingHetzner(false);
    }
  };

  const loadServerPolicies = async (serverId: number) => {
    setHetznerError("");
    try {
      const response = await apiRequest(`/v1/hetzner/servers/${serverId}/services`);
      if (!response.ok) {
        setHetznerError("Nao foi possivel carregar politicas do servidor.");
        return;
      }
      const rows = (await response.json()) as HetznerPolicy[];
      setHetznerPolicies(rows);
    } catch {
      setHetznerError("Falha de conexao ao carregar politicas.");
    }
  };

  useEffect(() => {
    const token = localStorage.getItem(ACCESS_TOKEN_KEY);
    if (!token) {
      router.replace("/login");
      return;
    }

    const savedEmail = localStorage.getItem(USER_EMAIL_KEY);
    if (savedEmail) setUserEmail(savedEmail);

    const loadMe = async () => {
      try {
        const response = await apiRequest("/v1/me");
        if (!response.ok) {
          onLogout();
          return;
        }
        const me = (await response.json()) as MeResponse;
        setMyRoles(me.roles);
        setMyAreas(me.areas);
        setUserEmail(me.email);
      } catch {
        onLogout();
      } finally {
        setIsReady(true);
      }
    };
    void loadMe();
  }, [router]);

  useEffect(() => {
    if (!isReady || !isAdmin) return;
    void loadCollaborators();
    void loadCompanies();
    void loadRunbooks();
  }, [isReady, isAdmin]);

  useEffect(() => {
    if (!isReady || !isAdmin || !selectedCompanyId) return;
    void loadCredentials(selectedCompanyId);
  }, [selectedCompanyId, isReady, isAdmin]);

  useEffect(() => {
    if (!isReady || !isAdmin || !selectedCompanyId) return;
    void loadHetznerData(selectedCompanyId);
  }, [selectedCompanyId, isReady, isAdmin]);

  useEffect(() => {
    setEditingCredentialId(null);
    setProvider("hetzner");
    setLabel("");
    setBaseUrl("");
    setAccountId("");
    setSecretValue("");
    setMetadataText("{}");
  }, [selectedCompanyId]);

  useEffect(() => {
    if (!hetznerCredentials.length) {
      setSelectedHetznerCredentialId(null);
      return;
    }
    if (selectedHetznerCredentialId && hetznerCredentials.some((item) => item.id === selectedHetznerCredentialId)) return;
    setSelectedHetznerCredentialId(hetznerCredentials[0].id);
  }, [credentials, selectedHetznerCredentialId]);

  useEffect(() => {
    if (!selectedHetznerServerId) return;
    void loadServerPolicies(selectedHetznerServerId);
  }, [selectedHetznerServerId]);

  useEffect(() => {
    const base = hetznerPolicies.find((row) => row.service_type === "backup") ?? hetznerPolicies[0];
    if (!base) return;
    setPolicyEnabled(base.enabled);
    setPolicyRequireConfirm(base.require_confirmation);
    setPolicyScheduleMode(base.schedule_mode);
    setPolicyIntervalMinutes(base.interval_minutes ? String(base.interval_minutes) : "60");
    setPolicyRetentionDays(base.retention_days ? String(base.retention_days) : "7");
    setPolicyRetentionCount(base.retention_count ? String(base.retention_count) : "7");
  }, [hetznerPolicies]);

  const onCreateUser = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setCollabError("");
    setCollabMessage("");
    const payload = {
      email: newEmail.trim().toLowerCase(),
      password: newPassword,
      roles: normalizeCsv(newRolesText),
      areas: normalizeCsv(newAreasText),
      status: newStatus
    };
    if (!payload.email || !payload.password || !payload.roles.length) {
      setCollabError("Preencha e-mail, senha e ao menos uma role.");
      return;
    }
    setCreatingUser(true);
    try {
      const response = await apiRequest("/v1/users", { method: "POST", body: JSON.stringify(payload) });
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao criar colaborador." }));
        setCollabError(body.detail ?? "Erro ao criar colaborador.");
        return;
      }
      setCollabMessage("Colaborador criado com sucesso.");
      setNewEmail("");
      setNewPassword("");
      await loadCollaborators();
    } catch {
      setCollabError("Falha de conexao ao criar colaborador.");
    } finally {
      setCreatingUser(false);
    }
  };

  const updateUserDraft = (userId: number, changes: Partial<UserDraft>) => {
    setUserDrafts((prev) => ({ ...prev, [userId]: { ...prev[userId], ...changes } }));
  };

  const onSaveUser = async (userId: number) => {
    const draft = userDrafts[userId];
    if (!draft) return;
    updateUserDraft(userId, { saving: true, error: "", message: "" });
    const payload: Record<string, unknown> = {
      roles: normalizeCsv(draft.rolesText),
      areas: normalizeCsv(draft.areasText),
      status: draft.status
    };
    if (draft.password.trim()) payload.password = draft.password;

    try {
      const response = await apiRequest(`/v1/users/${userId}`, { method: "PATCH", body: JSON.stringify(payload) });
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao atualizar colaborador." }));
        updateUserDraft(userId, { saving: false, error: body.detail ?? "Erro ao atualizar colaborador." });
        return;
      }
      updateUserDraft(userId, { saving: false, password: "", message: "Atualizado com sucesso." });
      await loadCollaborators();
    } catch {
      updateUserDraft(userId, { saving: false, error: "Falha de conexao ao atualizar colaborador." });
    }
  };

  const onCreateCompany = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApiError("");
    setApiMessage("");
    if (!companyName.trim()) {
      setApiError("Informe o nome da empresa.");
      return;
    }
    setCreatingCompany(true);
    try {
      const response = await apiRequest("/v1/companies", {
        method: "POST",
        body: JSON.stringify({ name: companyName.trim(), status: companyStatus })
      });
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao criar empresa." }));
        setApiError(body.detail ?? "Erro ao criar empresa.");
        return;
      }
      setApiMessage("Empresa criada com sucesso.");
      setCompanyName("");
      await loadCompanies();
    } catch {
      setApiError("Falha de conexao ao criar empresa.");
    } finally {
      setCreatingCompany(false);
    }
  };

  const onCreateCredential = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApiError("");
    setApiMessage("");
    if (!selectedCompanyId) {
      setApiError("Selecione uma empresa.");
      return;
    }
    if (!label.trim()) {
      setApiError("Informe rotulo.");
      return;
    }
    if (!editingCredentialId && !secretValue.trim()) {
      setApiError("Informe segredo para nova credencial.");
      return;
    }
    let metadata: Record<string, unknown>;
    try {
      metadata = parseMetadata(metadataText);
    } catch {
      setApiError("metadata_json invalido.");
      return;
    }
    setCreatingCredential(true);
    try {
      const payload: Record<string, unknown> = {
        provider,
        label: label.trim(),
        base_url: baseUrl.trim() || null,
        account_id: accountId.trim() || null,
        metadata_json: metadata
      };
      if (secretValue.trim()) payload.secret_value = secretValue.trim();

      const response = await apiRequest(
        editingCredentialId ? `/v1/api-credentials/${editingCredentialId}` : `/v1/companies/${selectedCompanyId}/api-credentials`,
        {
          method: editingCredentialId ? "PATCH" : "POST",
          body: JSON.stringify(payload)
        }
      );
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao salvar credencial." }));
        setApiError(body.detail ?? "Erro ao salvar credencial.");
        return;
      }
      setApiMessage(editingCredentialId ? "Credencial atualizada com sucesso." : "Credencial cadastrada com sucesso.");
      setEditingCredentialId(null);
      setLabel("");
      setBaseUrl("");
      setAccountId("");
      setSecretValue("");
      setMetadataText("{}");
      await loadCredentials(selectedCompanyId);
    } catch {
      setApiError("Falha de conexao ao salvar credencial.");
    } finally {
      setCreatingCredential(false);
    }
  };

  const onEditCredential = (credential: ApiCredential) => {
    setApiError("");
    setApiMessage("");
    setEditingCredentialId(credential.id);
    setProvider(credential.provider);
    setLabel(credential.label);
    setBaseUrl(credential.base_url ?? "");
    setAccountId(credential.account_id ?? "");
    setMetadataText(JSON.stringify(credential.metadata_json ?? {}, null, 2));
    setSecretValue("");
  };

  const onCancelEditCredential = () => {
    setEditingCredentialId(null);
    setProvider("hetzner");
    setLabel("");
    setBaseUrl("");
    setAccountId("");
    setSecretValue("");
    setMetadataText("{}");
  };

  const onDeleteCredential = async (credential: ApiCredential) => {
    if (!window.confirm(`Excluir credencial "${credential.label}"?`)) return;
    setDeletingCredentialId(credential.id);
    setApiError("");
    setApiMessage("");
    try {
      const response = await apiRequest(`/v1/api-credentials/${credential.id}`, { method: "DELETE" });
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao excluir credencial." }));
        setApiError(body.detail ?? "Erro ao excluir credencial.");
        return;
      }
      setApiMessage("Credencial excluida.");
      if (editingCredentialId === credential.id) onCancelEditCredential();
      if (selectedCompanyId) await loadCredentials(selectedCompanyId);
    } catch {
      setApiError("Falha de conexao ao excluir credencial.");
    } finally {
      setDeletingCredentialId(null);
    }
  };

  const onCreateHetznerServer = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!selectedCompanyId) {
      setHetznerError("Selecione uma empresa.");
      return;
    }
    if (!newServerExternalId.trim() || !newServerName.trim()) {
      setHetznerError("Preencha external_id e nome do servidor.");
      return;
    }
    let labels: Record<string, unknown> = {};
    try {
      labels = parseMetadata(newServerLabels);
    } catch {
      setHetznerError("labels_json invalido.");
      return;
    }
    setCreatingHetznerServer(true);
    setHetznerError("");
    setHetznerMessage("");
    try {
      const response = await apiRequest(`/v1/companies/${selectedCompanyId}/hetzner/servers`, {
        method: "POST",
        body: JSON.stringify({
          credential_id: selectedHetznerCredentialId,
          external_id: newServerExternalId.trim(),
          name: newServerName.trim(),
          datacenter: newServerDatacenter.trim() || null,
          ipv4: newServerIpv4.trim() || null,
          labels_json: labels
        })
      });
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao cadastrar servidor." }));
        setHetznerError(body.detail ?? "Erro ao cadastrar servidor.");
        return;
      }
      setHetznerMessage("Servidor cadastrado com sucesso.");
      setNewServerExternalId("");
      setNewServerName("");
      setNewServerDatacenter("");
      setNewServerIpv4("");
      setNewServerLabels("{}");
      await loadHetznerData(selectedCompanyId);
    } catch {
      setHetznerError("Falha de conexao ao cadastrar servidor.");
    } finally {
      setCreatingHetznerServer(false);
    }
  };

  const onImportHetznerServers = async () => {
    if (!selectedCompanyId || !selectedHetznerCredentialId) {
      setHetznerError("Selecione empresa e credencial Hetzner.");
      return;
    }
    setImportingHetzner(true);
    setHetznerError("");
    setHetznerMessage("");
    try {
      const response = await apiRequest(`/v1/companies/${selectedCompanyId}/hetzner/import`, {
        method: "POST",
        body: JSON.stringify({ credential_id: selectedHetznerCredentialId })
      });
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao importar servidores." }));
        setHetznerError(body.detail ?? "Erro ao importar servidores.");
        return;
      }
      const rows = (await response.json()) as HetznerServer[];
      setHetznerMessage(`Importacao concluida: ${rows.length} servidor(es).`);
      await loadHetznerData(selectedCompanyId);
    } catch {
      setHetznerError("Falha de conexao ao importar servidores.");
    } finally {
      setImportingHetzner(false);
    }
  };

  const onToggleServerAllow = async (server: HetznerServer, field: "allow_backup" | "allow_snapshot", value: boolean) => {
    setHetznerError("");
    try {
      const response = await apiRequest(`/v1/hetzner/servers/${server.id}`, {
        method: "PATCH",
        body: JSON.stringify({ [field]: value })
      });
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao atualizar servidor." }));
        setHetznerError(body.detail ?? "Erro ao atualizar servidor.");
        return;
      }
      if (selectedCompanyId) await loadHetznerData(selectedCompanyId);
    } catch {
      setHetznerError("Falha de conexao ao atualizar servidor.");
    }
  };

  const onAssignServerCredential = async (server: HetznerServer, credentialIdValue: string) => {
    const credentialId = credentialIdValue ? Number(credentialIdValue) : null;
    setHetznerError("");
    setHetznerMessage("");
    setUpdatingServerId(server.id);
    try {
      const response = await apiRequest(`/v1/hetzner/servers/${server.id}`, {
        method: "PATCH",
        body: JSON.stringify({ credential_id: credentialId })
      });
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao atualizar credencial do servidor." }));
        setHetznerError(body.detail ?? "Erro ao atualizar credencial do servidor.");
        return;
      }
      setHetznerMessage("Credencial do servidor atualizada.");
      if (selectedCompanyId) await loadHetznerData(selectedCompanyId);
    } catch {
      setHetznerError("Falha de conexao ao atualizar credencial do servidor.");
    } finally {
      setUpdatingServerId(null);
    }
  };

  const onSelectServer = (serverId: number) => {
    setSelectedHetznerServerId(serverId);
    setServerDetailTab("dashboard");
  };

  const onToggleMaintenance = async (server: HetznerServer, enabled: boolean) => {
    const labels = { ...(server.labels_json ?? {}) };
    if (enabled) labels.maintenance = true;
    else delete labels.maintenance;
    setHetznerError("");
    setHetznerMessage("");
    setUpdatingServerId(server.id);
    try {
      const response = await apiRequest(`/v1/hetzner/servers/${server.id}`, {
        method: "PATCH",
        body: JSON.stringify({ labels_json: labels })
      });
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao atualizar manutencao." }));
        setHetznerError(body.detail ?? "Erro ao atualizar manutencao.");
        return;
      }
      setHetznerMessage(enabled ? "Modo manutencao ativado." : "Modo manutencao desativado.");
      if (selectedCompanyId) await loadHetznerData(selectedCompanyId);
    } catch {
      setHetznerError("Falha de conexao ao atualizar manutencao.");
    } finally {
      setUpdatingServerId(null);
    }
  };

  const onExecuteRunbook = async () => {
    if (!selectedServer) {
      setHetznerError("Selecione um servidor.");
      return;
    }
    if (!selectedRunbookName) {
      setHetznerError("Selecione um runbook.");
      return;
    }
    setHetznerError("");
    setHetznerMessage("");
    setExecutingRunbook(true);
    try {
      const response = await apiRequest(`/v1/runbooks/${encodeURIComponent(selectedRunbookName)}/execute`, {
        method: "POST",
        body: JSON.stringify({
          inputs: {
            server_id: selectedServer.id,
            server_name: selectedServer.name,
            server_external_id: selectedServer.external_id,
            company_id: selectedServer.company_id
          }
        })
      });
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao executar runbook." }));
        setHetznerError(body.detail ?? "Erro ao executar runbook.");
        return;
      }
      const job = (await response.json()) as { job_id: number; status: string };
      setHetznerMessage(`Runbook enviado. Job #${job.job_id} (${job.status}).`);
    } catch {
      setHetznerError("Falha de conexao ao executar runbook.");
    } finally {
      setExecutingRunbook(false);
    }
  };

  const onSavePolicy = async (serviceType: "backup" | "snapshot") => {
    if (!selectedHetznerServerId) {
      setHetznerError("Selecione um servidor.");
      return;
    }
    setHetznerError("");
    setHetznerMessage("");
    try {
      const response = await apiRequest(`/v1/hetzner/servers/${selectedHetznerServerId}/services/${serviceType}`, {
        method: "PUT",
        body: JSON.stringify({
          enabled: policyEnabled,
          require_confirmation: policyRequireConfirm,
          schedule_mode: policyScheduleMode,
          interval_minutes: policyScheduleMode === "interval" ? Number(policyIntervalMinutes) : null,
          retention_days: Number(policyRetentionDays),
          retention_count: Number(policyRetentionCount)
        })
      });
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao salvar politica." }));
        setHetznerError(body.detail ?? "Erro ao salvar politica.");
        return;
      }
      setHetznerMessage(`Politica de ${serviceType} salva.`);
      await loadServerPolicies(selectedHetznerServerId);
      if (selectedCompanyId) await loadHetznerData(selectedCompanyId);
    } catch {
      setHetznerError("Falha de conexao ao salvar politica.");
    }
  };

  const onRunNow = async (serviceType: "backup" | "snapshot") => {
    if (!selectedHetznerServerId) {
      setHetznerError("Selecione um servidor.");
      return;
    }
    setHetznerError("");
    setHetznerMessage("");
    try {
      const response = await apiRequest(`/v1/hetzner/servers/${selectedHetznerServerId}/services/${serviceType}/run`, {
        method: "POST",
        body: JSON.stringify({ confirm: true })
      });
      if (!response.ok) {
        const body = await response.json().catch(() => ({ detail: "Erro ao executar servico." }));
        setHetznerError(body.detail ?? "Erro ao executar servico.");
        return;
      }
      setHetznerMessage(`Execucao de ${serviceType} enviada.`);
      await loadServerPolicies(selectedHetznerServerId);
      if (selectedCompanyId) await loadHetznerData(selectedCompanyId);
    } catch {
      setHetznerError("Falha de conexao ao executar servico.");
    }
  };

  const alertSet = useMemo(() => new Set(["atraso", "falha", "sem_politica"]), []);
  const statusByServerId = useMemo(() => {
    const map = new Map<number, HetznerStatus[]>();
    for (const item of hetznerStatus) {
      const rows = map.get(item.server_id) ?? [];
      rows.push(item);
      map.set(item.server_id, rows);
    }
    return map;
  }, [hetznerStatus]);
  const filteredServers = useMemo(() => {
    const query = serverSearch.trim().toLowerCase();
    return hetznerServers.filter((server) => {
      const maintenance = Boolean(server.labels_json?.maintenance);
      const statuses = statusByServerId.get(server.id) ?? [];
      const hasAlert = statuses.some((row) => alertSet.has(row.status));
      if (serverFilter === "healthy" && hasAlert) return false;
      if (serverFilter === "alerting" && !hasAlert) return false;
      if (serverFilter === "maintenance" && !maintenance) return false;
      if (!query) return true;
      return (
        server.name.toLowerCase().includes(query) ||
        server.external_id.toLowerCase().includes(query) ||
        (server.datacenter ?? "").toLowerCase().includes(query) ||
        (server.ipv4 ?? "").toLowerCase().includes(query)
      );
    });
  }, [alertSet, hetznerServers, serverFilter, serverSearch, statusByServerId]);
  const serversWithAlerts = useMemo(
    () => hetznerServers.filter((server) => (statusByServerId.get(server.id) ?? []).some((item) => alertSet.has(item.status))).length,
    [alertSet, hetznerServers, statusByServerId]
  );
  const healthyServers = Math.max(hetznerServers.length - serversWithAlerts, 0);
  const overallHealthPct = hetznerServers.length ? Math.round((healthyServers / hetznerServers.length) * 100) : 0;
  const backupEnabledCount = hetznerServers.filter((server) => server.allow_backup).length;
  const snapshotEnabledCount = hetznerServers.filter((server) => server.allow_snapshot).length;
  const selectedMaintenance = Boolean(selectedServer?.labels_json?.maintenance);
  const selectedServerCpu = selectedServer ? metricFromLabels(selectedServer.labels_json, ["cpu", "cpu_pct", "cpu_usage"]) : "n/d";
  const selectedServerRam = selectedServer ? metricFromLabels(selectedServer.labels_json, ["ram", "ram_pct", "ram_usage"]) : "n/d";
  const selectedServerDisk = selectedServer ? metricFromLabels(selectedServer.labels_json, ["disk", "disk_pct", "disk_usage"]) : "n/d";
  const selectedServerPing = selectedServer ? metricFromLabels(selectedServer.labels_json, ["ping", "ping_ms", "latency_ms"]) : "n/d";
  const selectedServerJobsCount = selectedServerLogs.length;
  const selectedServerUptime = selectedServerStatuses.length
    ? `${Math.round((selectedServerStatuses.filter((row) => row.status === "ok").length / selectedServerStatuses.length) * 100)}%`
    : "n/d";
  const nowMs = Date.now();
  const logsIn1h = selectedServerLogs.filter((row) => {
    const date = toDateSafe(row.created_at);
    return date ? nowMs - date.getTime() <= 60 * 60 * 1000 : false;
  }).length;
  const logsIn24h = selectedServerLogs.filter((row) => {
    const date = toDateSafe(row.created_at);
    return date ? nowMs - date.getTime() <= 24 * 60 * 60 * 1000 : false;
  }).length;
  const logsIn7d = selectedServerLogs.filter((row) => {
    const date = toDateSafe(row.created_at);
    return date ? nowMs - date.getTime() <= 7 * 24 * 60 * 60 * 1000 : false;
  }).length;

  const serverManagementView = (
    <section className={styles.panelGrid}>
      <article className={styles.panel}>
        <h2 className={styles.panelTitle}>Servidores (lista geral)</h2>
        <div className={styles.formGrid}>
          <select className={styles.input} value={selectedCompanyId ?? ""} onChange={(e) => setSelectedCompanyId(Number(e.target.value))}>
            {companies.length === 0 ? <option value="">Sem empresas</option> : null}
            {companies.map((company) => (
              <option key={company.id} value={company.id}>
                {company.name} ({company.status})
              </option>
            ))}
          </select>
          <input
            className={styles.input}
            placeholder="Buscar servidor, datacenter, IP..."
            value={serverSearch}
            onChange={(e) => setServerSearch(e.target.value)}
          />
          <select className={styles.input} value={serverFilter} onChange={(e) => setServerFilter(e.target.value as "all" | "healthy" | "alerting" | "maintenance")}>
            <option value="all">Todos</option>
            <option value="healthy">Saudaveis</option>
            <option value="alerting">Com alerta</option>
            <option value="maintenance">Em manutencao</option>
          </select>
          <select className={styles.input} value={selectedHetznerCredentialId ?? ""} onChange={(e) => setSelectedHetznerCredentialId(Number(e.target.value))}>
            {hetznerCredentials.length === 0 ? <option value="">Sem credencial Hetzner</option> : null}
            {hetznerCredentials.map((cred) => (
              <option key={cred.id} value={cred.id}>
                {cred.label}
              </option>
            ))}
          </select>
          <button
            type="button"
            className={styles.submitButton}
            disabled={!selectedCompanyId || !selectedHetznerCredentialId || importingHetzner}
            onClick={onImportHetznerServers}
          >
            {importingHetzner ? "Importando..." : "Importar da API"}
          </button>
        </div>

        <form className={styles.formGrid} style={{ marginTop: 10 }} onSubmit={onCreateHetznerServer}>
          <input className={styles.input} placeholder="External ID" value={newServerExternalId} onChange={(e) => setNewServerExternalId(e.target.value)} />
          <input className={styles.input} placeholder="Nome" value={newServerName} onChange={(e) => setNewServerName(e.target.value)} />
          <input className={styles.input} placeholder="Datacenter" value={newServerDatacenter} onChange={(e) => setNewServerDatacenter(e.target.value)} />
          <input className={styles.input} placeholder="IPv4" value={newServerIpv4} onChange={(e) => setNewServerIpv4(e.target.value)} />
          <textarea className={styles.input} placeholder='labels_json ({"env":"prod"})' value={newServerLabels} onChange={(e) => setNewServerLabels(e.target.value)} />
          <button className={styles.secondaryButton} type="submit" disabled={creatingHetznerServer || !selectedCompanyId}>
            {creatingHetznerServer ? "Salvando..." : "Cadastrar servidor"}
          </button>
        </form>

        <div className={styles.tableWrap} style={{ marginTop: 12 }}>
          <table className={styles.table}>
            <thead>
              <tr>
                <th>Servidor</th>
                <th>Status</th>
                <th>Uptime</th>
                <th>CPU/RAM/Disco</th>
                <th>Backup/Snapshot</th>
                <th>Credencial</th>
                <th>Acoes</th>
              </tr>
            </thead>
            <tbody>
              {filteredServers.map((srv) => {
                const statuses = statusByServerId.get(srv.id) ?? [];
                const hasAlert = statuses.some((row) => alertSet.has(row.status));
                const uptime = statuses.length
                  ? `${Math.round((statuses.filter((row) => row.status === "ok").length / statuses.length) * 100)}%`
                  : "n/d";
                const cpu = metricFromLabels(srv.labels_json, ["cpu", "cpu_pct", "cpu_usage"]);
                const ram = metricFromLabels(srv.labels_json, ["ram", "ram_pct", "ram_usage"]);
                const disk = metricFromLabels(srv.labels_json, ["disk", "disk_pct", "disk_usage"]);
                return (
                  <tr key={srv.id} className={selectedServer?.id === srv.id ? styles.selectedRow : ""}>
                    <td>
                      <strong>{srv.name}</strong>
                      <p className={styles.sub}>{srv.external_id}</p>
                    </td>
                    <td>{hasAlert ? <span className={styles.statusErr}>alerta</span> : <span className={styles.statusOk}>ok</span>}</td>
                    <td>{uptime}</td>
                    <td>{`${cpu}/${ram}/${disk}`}</td>
                    <td>{`${srv.allow_backup ? "on" : "off"} / ${srv.allow_snapshot ? "on" : "off"}`}</td>
                    <td>
                      <select
                        className={styles.inlineInput}
                        value={srv.credential_id ?? ""}
                        disabled={updatingServerId === srv.id}
                        onChange={(e) => void onAssignServerCredential(srv, e.target.value)}
                      >
                        <option value="">Sem credencial</option>
                        {hetznerCredentials.map((cred) => (
                          <option key={cred.id} value={cred.id}>
                            {cred.label}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td>
                      <button type="button" className={styles.smallButton} onClick={() => onSelectServer(srv.id)}>
                        Abrir
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        {hetznerError ? <p className={styles.statusError}>{hetznerError}</p> : null}
        {hetznerMessage ? <p className={styles.statusOk}>{hetznerMessage}</p> : null}
      </article>

      <article className={styles.panel}>
        {!selectedServer ? (
          <p className={styles.sub}>Selecione um servidor para abrir Dashboard, Controles, Alertas e Logs.</p>
        ) : (
          <>
            <h2 className={styles.panelTitle}>{`${selectedServer.name} (${selectedServer.external_id})`}</h2>
            <p className={styles.sub}>
              Datacenter: {selectedServer.datacenter ?? "-"} | IP: {selectedServer.ipv4 ?? "-"} | Credencial:{" "}
              {selectedServer.credential_id ? credentialLabelById.get(selectedServer.credential_id) ?? `#${selectedServer.credential_id}` : "nao vinculada"}
            </p>
            <div className={styles.tabBar}>
              {(["dashboard", "controles", "alertas", "logs"] as const).map((tab) => (
                <button
                  key={tab}
                  type="button"
                  className={`${styles.tabButton} ${serverDetailTab === tab ? styles.tabButtonActive : ""}`}
                  onClick={() => setServerDetailTab(tab)}
                >
                  {tab === "dashboard" ? "Dashboard" : tab === "controles" ? "Controles" : tab === "alertas" ? "Alertas" : "Logs"}
                </button>
              ))}
            </div>
            {serverDetailTab === "dashboard" ? (
              <div className={styles.kpiGrid}>
                <article className={styles.kpiCard}><p className={styles.cardLabel}>CPU</p><p className={styles.cardValue}>{selectedServerCpu}</p></article>
                <article className={styles.kpiCard}><p className={styles.cardLabel}>RAM</p><p className={styles.cardValue}>{selectedServerRam}</p></article>
                <article className={styles.kpiCard}><p className={styles.cardLabel}>Disco</p><p className={styles.cardValue}>{selectedServerDisk}</p></article>
                <article className={styles.kpiCard}><p className={styles.cardLabel}>Ping</p><p className={styles.cardValue}>{selectedServerPing}</p></article>
                <article className={styles.kpiCard}><p className={styles.cardLabel}>Uptime</p><p className={styles.cardValue}>{selectedServerUptime}</p></article>
                <article className={styles.kpiCard}><p className={styles.cardLabel}>Jobs</p><p className={styles.cardValue}>{selectedServerJobsCount}</p></article>
                <article className={styles.kpiCard}><p className={styles.cardLabel}>Eventos 1h/24h/7d</p><p className={styles.cardValue}>{`${logsIn1h}/${logsIn24h}/${logsIn7d}`}</p></article>
                <article className={styles.kpiCard}><p className={styles.cardLabel}>Alertas ativos</p><p className={styles.cardValue}>{selectedServerAlerts.length}</p></article>
              </div>
            ) : null}
            {serverDetailTab === "controles" ? (
              <div className={styles.formGrid}>
                <button type="button" className={styles.submitButton} onClick={() => void onRunNow("backup")}>Snapshot/Backup agora</button>
                <button type="button" className={styles.submitButton} onClick={() => void onRunNow("snapshot")}>Snapshot manual</button>
                <button type="button" className={styles.secondaryButton} onClick={() => void onToggleMaintenance(selectedServer, !selectedMaintenance)}>
                  {selectedMaintenance ? "Desativar manutencao" : "Ativar manutencao"}
                </button>
                <select className={styles.input} value={selectedRunbookName} onChange={(e) => setSelectedRunbookName(e.target.value)}>
                  {runbooks.length === 0 ? <option value="">Sem runbooks</option> : null}
                  {runbooks.map((row) => (
                    <option key={row.name} value={row.name}>{row.name}</option>
                  ))}
                </select>
                <button type="button" className={styles.smallButton} disabled={!selectedRunbookName || executingRunbook} onClick={onExecuteRunbook}>
                  {executingRunbook ? "Executando..." : "Rodar runbook"}
                </button>
              </div>
            ) : null}
            {serverDetailTab === "alertas" ? (
              <div className={styles.tableWrap}>
                <table className={styles.table}>
                  <thead><tr><th>Servico</th><th>Status</th><th>Severidade</th><th>Detalhes</th><th>Ultima execucao</th></tr></thead>
                  <tbody>
                    {selectedServerAlerts.length === 0 ? <tr><td colSpan={5}>Sem alertas ativos.</td></tr> : null}
                    {selectedServerAlerts.map((item, idx) => (
                      <tr key={`${item.server_id}-${item.service_type}-${idx}`}>
                        <td>{item.service_type}</td><td>{item.status}</td><td>{item.status === "falha" ? "critico" : "medio"}</td>
                        <td>{item.details}</td><td>{formatDateTime(item.last_run_at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : null}
            {serverDetailTab === "logs" ? (
              <div className={styles.tableWrap}>
                <table className={styles.table}>
                  <thead><tr><th>Quando</th><th>Servico</th><th>Acao</th><th>Status</th><th>Mensagem</th></tr></thead>
                  <tbody>
                    {selectedServerLogs.length === 0 ? <tr><td colSpan={5}>Sem logs.</td></tr> : null}
                    {selectedServerLogs.map((log) => (
                      <tr key={log.id}><td>{formatDateTime(log.created_at)}</td><td>{log.service_type}</td><td>{log.action}</td><td>{log.status}</td><td>{log.message}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : null}
          </>
        )}
      </article>
    </section>
  );

  return (
    <div className={styles.shell}>
      <aside className={styles.sidebar}>
        <div className={styles.brand}>
          Omni<span className={styles.brandAccent}>NOC</span>
        </div>
        <p className={styles.menuTitle}>Navegacao</p>
        <nav className={styles.menu}>
          {menu.map((item) => (
            <button
              key={item}
              className={`${styles.menuItem} ${
                (item === "Colaboradores" && activeView === "colaboradores") ||
                (item === "APIs Empresas" && activeView === "apis") ||
                (item === "Servidores" && activeView === "hetzner")
                  ? styles.menuItemActive
                  : ""
              }`}
              type="button"
              onClick={() => {
                if (item === "Colaboradores") setActiveView("colaboradores");
                if (item === "APIs Empresas") setActiveView("apis");
                if (item === "Servidores") setActiveView("hetzner");
              }}
            >
              {item}
            </button>
          ))}
        </nav>
      </aside>

      <section className={styles.content}>
        <header className={styles.topbar}>
          <input className={styles.search} placeholder="Buscar..." />
          <div className={styles.topActions}>
            <span>Perfil: {myRoles.join(",") || "-"}</span>
            <span>Usuario: {userEmail}</span>
            <button type="button" className={styles.logoutButton} onClick={onLogout}>
              Sair
            </button>
          </div>
        </header>

        <main className={styles.main}>
          <h1 className={styles.heading}>
            {activeView === "colaboradores"
              ? "Colaboradores e Permissoes"
              : activeView === "apis"
                ? "Cadastro de APIs por Empresa"
                : "Servidores e Monitoramento"}
          </h1>
          <p className={styles.sub}>
            {activeView === "colaboradores"
              ? "Gerencie usuarios, perfis e areas de uso da plataforma."
              : activeView === "apis"
                ? "Cadastre APIs de Hetzner, Cloudflare, n8n, Portainer, OpenAI, OpenRouter, PostgreSQL e Chatwoot."
                : "Lista geral por servidor com dashboard, controles, alertas e logs."}
          </p>

          {activeView !== "hetzner" ? (
            <section className={styles.cards}>
              <article className={styles.card}>
                <p className={styles.cardLabel}>Minhas areas</p>
                <p className={styles.cardValue}>{myAreas.length}</p>
              </article>
              <article className={styles.card}>
                <p className={styles.cardLabel}>Colaboradores</p>
                <p className={styles.cardValue}>{users.length}</p>
              </article>
              <article className={styles.card}>
                <p className={styles.cardLabel}>Empresas</p>
                <p className={styles.cardValue}>{companies.length}</p>
              </article>
              <article className={styles.card}>
                <p className={styles.cardLabel}>Credenciais API</p>
                <p className={styles.cardValue}>{credentials.length}</p>
              </article>
            </section>
          ) : (
            <section className={styles.cards}>
              <article className={styles.card}>
                <p className={styles.cardLabel}>Servidores</p>
                <p className={styles.cardValue}>{hetznerServers.length}</p>
              </article>
              <article className={styles.card}>
                <p className={styles.cardLabel}>Saude Geral</p>
                <p className={styles.cardValue}>{overallHealthPct}%</p>
              </article>
              <article className={styles.card}>
                <p className={styles.cardLabel}>Com Alerta</p>
                <p className={styles.cardValue}>{serversWithAlerts}</p>
              </article>
              <article className={styles.card}>
                <p className={styles.cardLabel}>Backup/Snapshot ON</p>
                <p className={styles.cardValue}>{`${backupEnabledCount}/${snapshotEnabledCount}`}</p>
              </article>
            </section>
          )}

          {!isAdmin ? (
            <article className={styles.panel}>
              <h2 className={styles.panelTitle}>Acesso restrito</h2>
              <p className={styles.sub}>Somente administradores podem gerenciar cadastros.</p>
            </article>
          ) : activeView === "colaboradores" ? (
            <section className={styles.panelGrid}>
              <article className={styles.panel}>
                <h2 className={styles.panelTitle}>Cadastrar colaborador</h2>
                <form className={styles.formGrid} onSubmit={onCreateUser}>
                  <input className={styles.input} placeholder="E-mail" value={newEmail} onChange={(e) => setNewEmail(e.target.value)} />
                  <input
                    className={styles.input}
                    placeholder="Senha inicial"
                    value={newPassword}
                    onChange={(e) => setNewPassword(e.target.value)}
                  />
                  <input
                    className={styles.input}
                    placeholder={`Roles (${roleHints})`}
                    value={newRolesText}
                    onChange={(e) => setNewRolesText(e.target.value)}
                  />
                  <input
                    className={styles.input}
                    placeholder={`Areas (${areas.map((area) => area.name).join(",") || "general"})`}
                    value={newAreasText}
                    onChange={(e) => setNewAreasText(e.target.value)}
                  />
                  <select className={styles.input} value={newStatus} onChange={(e) => setNewStatus(e.target.value)}>
                    <option value="active">active</option>
                    <option value="disabled">disabled</option>
                  </select>
                  <button className={styles.submitButton} type="submit" disabled={creatingUser}>
                    {creatingUser ? "Criando..." : "Criar colaborador"}
                  </button>
                </form>
                {collabError ? <p className={styles.statusError}>{collabError}</p> : null}
                {collabMessage ? <p className={styles.statusOk}>{collabMessage}</p> : null}
              </article>

              <article className={styles.panel}>
                <h2 className={styles.panelTitle}>Colaboradores cadastrados</h2>
                {loadingUsers ? <p className={styles.sub}>Carregando...</p> : null}
                <div className={styles.tableWrap}>
                  <table className={styles.table}>
                    <thead>
                      <tr>
                        <th>Usuario</th>
                        <th>Roles</th>
                        <th>Areas</th>
                        <th>Status</th>
                        <th>Nova senha</th>
                        <th>Acao</th>
                      </tr>
                    </thead>
                    <tbody>
                      {users.map((user) => {
                        const draft = userDrafts[user.id];
                        return (
                          <tr key={user.id}>
                            <td>{user.email}</td>
                            <td>
                              <input
                                className={styles.inlineInput}
                                value={draft?.rolesText ?? ""}
                                onChange={(e) => updateUserDraft(user.id, { rolesText: e.target.value })}
                              />
                            </td>
                            <td>
                              <input
                                className={styles.inlineInput}
                                value={draft?.areasText ?? ""}
                                onChange={(e) => updateUserDraft(user.id, { areasText: e.target.value })}
                              />
                            </td>
                            <td>
                              <select
                                className={styles.inlineInput}
                                value={draft?.status ?? "active"}
                                onChange={(e) => updateUserDraft(user.id, { status: e.target.value })}
                              >
                                <option value="active">active</option>
                                <option value="disabled">disabled</option>
                              </select>
                            </td>
                            <td>
                              <input
                                className={styles.inlineInput}
                                placeholder="Opcional"
                                value={draft?.password ?? ""}
                                onChange={(e) => updateUserDraft(user.id, { password: e.target.value })}
                              />
                            </td>
                            <td>
                              <button
                                type="button"
                                className={styles.smallButton}
                                disabled={draft?.saving}
                                onClick={() => onSaveUser(user.id)}
                              >
                                {draft?.saving ? "Salvando..." : "Salvar"}
                              </button>
                              {draft?.error ? <p className={styles.statusError}>{draft.error}</p> : null}
                              {draft?.message ? <p className={styles.statusOk}>{draft.message}</p> : null}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </article>
            </section>
          ) : activeView === "apis" ? (
            <section className={styles.panelGrid}>
              <article className={styles.panel}>
                <h2 className={styles.panelTitle}>Cadastrar empresa</h2>
                <form className={styles.formGrid} onSubmit={onCreateCompany}>
                  <input
                    className={styles.input}
                    placeholder="Nome da empresa"
                    value={companyName}
                    onChange={(e) => setCompanyName(e.target.value)}
                  />
                  <select className={styles.input} value={companyStatus} onChange={(e) => setCompanyStatus(e.target.value)}>
                    <option value="active">active</option>
                    <option value="disabled">disabled</option>
                  </select>
                  <button className={styles.submitButton} type="submit" disabled={creatingCompany}>
                    {creatingCompany ? "Criando..." : "Criar empresa"}
                  </button>
                </form>

                <h2 className={styles.panelTitle}>Empresa ativa</h2>
                <select
                  className={styles.input}
                  value={selectedCompanyId ?? ""}
                  onChange={(e) => setSelectedCompanyId(Number(e.target.value))}
                >
                  {companies.length === 0 ? <option value="">Sem empresas</option> : null}
                  {companies.map((company) => (
                    <option key={company.id} value={company.id}>
                      {company.name} ({company.status})
                    </option>
                  ))}
                </select>

                <h2 className={styles.panelTitle}>{editingCredentialId ? "Editar credencial API" : "Cadastrar credencial API"}</h2>
                <form className={styles.formGrid} onSubmit={onCreateCredential}>
                  <select className={styles.input} value={provider} onChange={(e) => setProvider(e.target.value)} disabled={Boolean(editingCredentialId)}>
                    {providerOptions.map((item) => (
                      <option key={item} value={item}>
                        {item}
                      </option>
                    ))}
                  </select>
                  <input className={styles.input} placeholder="Rotulo" value={label} onChange={(e) => setLabel(e.target.value)} />
                  <input
                    className={styles.input}
                    placeholder="Base URL / endpoint"
                    value={baseUrl}
                    onChange={(e) => setBaseUrl(e.target.value)}
                  />
                  <input
                    className={styles.input}
                    placeholder="Account / Project ID"
                    value={accountId}
                    onChange={(e) => setAccountId(e.target.value)}
                  />
                  <input
                    className={styles.input}
                    placeholder={editingCredentialId ? "Novo secret (opcional)" : "Secret / API Token / Key"}
                    value={secretValue}
                    onChange={(e) => setSecretValue(e.target.value)}
                  />
                  <textarea
                    className={styles.input}
                    placeholder='metadata_json (ex: {"bucket":"omninocn8n"})'
                    value={metadataText}
                    onChange={(e) => setMetadataText(e.target.value)}
                  />
                  <button className={styles.submitButton} type="submit" disabled={creatingCredential || !selectedCompanyId}>
                    {creatingCredential ? "Salvando..." : editingCredentialId ? "Salvar alteracoes" : "Cadastrar API"}
                  </button>
                  {editingCredentialId ? (
                    <button type="button" className={styles.secondaryButton} onClick={onCancelEditCredential}>
                      Cancelar edicao
                    </button>
                  ) : null}
                </form>
                {apiError ? <p className={styles.statusError}>{apiError}</p> : null}
                {apiMessage ? <p className={styles.statusOk}>{apiMessage}</p> : null}
              </article>

              <article className={styles.panel}>
                <h2 className={styles.panelTitle}>Credenciais cadastradas</h2>
                {loadingApis ? <p className={styles.sub}>Carregando...</p> : null}
                <div className={styles.tableWrap}>
                  <table className={styles.table}>
                    <thead>
                      <tr>
                        <th>Provider</th>
                        <th>Rotulo</th>
                        <th>Base URL</th>
                        <th>Conta</th>
                        <th>Metadata</th>
                        <th>Secret</th>
                        <th>Uso em servidores</th>
                        <th>Acoes</th>
                      </tr>
                    </thead>
                    <tbody>
                      {credentials.map((row) => (
                        <tr key={row.id}>
                          <td>{row.provider}</td>
                          <td>{row.label}</td>
                          <td>{row.base_url ?? "-"}</td>
                          <td>{row.account_id ?? "-"}</td>
                          <td>
                            <pre className={styles.preCell}>{JSON.stringify(row.metadata_json ?? {}, null, 2)}</pre>
                          </td>
                          <td>{row.secret_present ? "configurado" : "nao configurado"}</td>
                          <td>{hetznerServers.filter((srv) => srv.credential_id === row.id).length}</td>
                          <td className={styles.actionsCell}>
                            <button type="button" className={styles.smallButton} onClick={() => onEditCredential(row)}>
                              Editar
                            </button>
                            <button
                              type="button"
                              className={styles.smallButtonDanger}
                              disabled={deletingCredentialId === row.id}
                              onClick={() => onDeleteCredential(row)}
                            >
                              {deletingCredentialId === row.id ? "Excluindo..." : "Excluir"}
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </article>
            </section>
          ) : activeView === "hetzner" ? (
            serverManagementView
          ) : (
            <section className={styles.panelGrid}>
              <article className={styles.panel}>
                <h2 className={styles.panelTitle}>Empresa ativa</h2>
                <select
                  className={styles.input}
                  value={selectedCompanyId ?? ""}
                  onChange={(e) => setSelectedCompanyId(Number(e.target.value))}
                >
                  {companies.length === 0 ? <option value="">Sem empresas</option> : null}
                  {companies.map((company) => (
                    <option key={company.id} value={company.id}>
                      {company.name} ({company.status})
                    </option>
                  ))}
                </select>

                <div className={styles.menu} style={{ marginTop: 12, marginBottom: 12 }}>
                  {(["cadastro", "servicos", "alertas", "logs"] as const).map((section) => (
                    <button
                      key={section}
                      type="button"
                      className={`${styles.menuItem} ${hetznerSection === section ? styles.menuItemActive : ""}`}
                      onClick={() => setHetznerSection(section)}
                    >
                      {section === "cadastro" ? "Cadastro" : section === "servicos" ? "Servicos" : section === "alertas" ? "Alertas" : "Logs"}
                    </button>
                  ))}
                </div>

                {hetznerSection === "cadastro" ? (
                  <>
                    <h2 className={styles.panelTitle}>Importar da API Hetzner</h2>
                    <select
                      className={styles.input}
                      value={selectedHetznerCredentialId ?? ""}
                      onChange={(e) => setSelectedHetznerCredentialId(Number(e.target.value))}
                    >
                      {hetznerCredentials.length === 0 ? <option value="">Sem credencial hetzner</option> : null}
                      {hetznerCredentials.map((cred) => (
                        <option key={cred.id} value={cred.id}>
                          {cred.label}
                        </option>
                      ))}
                    </select>
                    <button
                      type="button"
                      className={styles.submitButton}
                      style={{ marginTop: 10 }}
                      disabled={!selectedCompanyId || !selectedHetznerCredentialId || importingHetzner}
                      onClick={onImportHetznerServers}
                    >
                      {importingHetzner ? "Importando..." : "Importar servidores"}
                    </button>

                    <h2 className={styles.panelTitle} style={{ marginTop: 16 }}>Cadastro manual de servidor</h2>
                    <form className={styles.formGrid} onSubmit={onCreateHetznerServer}>
                      <input
                        className={styles.input}
                        placeholder="External ID (Hetzner)"
                        value={newServerExternalId}
                        onChange={(e) => setNewServerExternalId(e.target.value)}
                      />
                      <input className={styles.input} placeholder="Nome" value={newServerName} onChange={(e) => setNewServerName(e.target.value)} />
                      <input
                        className={styles.input}
                        placeholder="Datacenter"
                        value={newServerDatacenter}
                        onChange={(e) => setNewServerDatacenter(e.target.value)}
                      />
                      <input className={styles.input} placeholder="IPv4" value={newServerIpv4} onChange={(e) => setNewServerIpv4(e.target.value)} />
                      <textarea
                        className={styles.input}
                        placeholder='labels_json (ex: {"env":"prod"})'
                        value={newServerLabels}
                        onChange={(e) => setNewServerLabels(e.target.value)}
                      />
                      <button className={styles.submitButton} type="submit" disabled={creatingHetznerServer || !selectedCompanyId}>
                        {creatingHetznerServer ? "Salvando..." : "Cadastrar servidor"}
                      </button>
                    </form>
                  </>
                ) : null}

                {hetznerSection === "servicos" ? (
                  <>
                    <h2 className={styles.panelTitle}>Servidor alvo</h2>
                    <select
                      className={styles.input}
                      value={selectedHetznerServerId ?? ""}
                      onChange={(e) => setSelectedHetznerServerId(Number(e.target.value))}
                    >
                      {hetznerServers.length === 0 ? <option value="">Sem servidores</option> : null}
                      {hetznerServers.map((srv) => (
                        <option key={srv.id} value={srv.id}>
                          {srv.name} ({srv.external_id})
                        </option>
                      ))}
                    </select>

                    <h2 className={styles.panelTitle} style={{ marginTop: 16 }}>Politica</h2>
                    <div className={styles.formGrid}>
                      <select className={styles.input} value={policyEnabled ? "enabled" : "disabled"} onChange={(e) => setPolicyEnabled(e.target.value === "enabled")}>
                        <option value="enabled">enabled</option>
                        <option value="disabled">disabled</option>
                      </select>
                      <select
                        className={styles.input}
                        value={policyRequireConfirm ? "yes" : "no"}
                        onChange={(e) => setPolicyRequireConfirm(e.target.value === "yes")}
                      >
                        <option value="yes">confirmacao obrigatoria</option>
                        <option value="no">sem confirmacao</option>
                      </select>
                      <select className={styles.input} value={policyScheduleMode} onChange={(e) => setPolicyScheduleMode(e.target.value as "manual" | "interval")}>
                        <option value="manual">manual</option>
                        <option value="interval">interval</option>
                      </select>
                      <input
                        className={styles.input}
                        placeholder="Intervalo (minutos)"
                        value={policyIntervalMinutes}
                        onChange={(e) => setPolicyIntervalMinutes(e.target.value)}
                      />
                      <input
                        className={styles.input}
                        placeholder="Retencao (dias)"
                        value={policyRetentionDays}
                        onChange={(e) => setPolicyRetentionDays(e.target.value)}
                      />
                      <input
                        className={styles.input}
                        placeholder="Retencao (quantidade)"
                        value={policyRetentionCount}
                        onChange={(e) => setPolicyRetentionCount(e.target.value)}
                      />
                    </div>
                    <div className={styles.formGrid} style={{ marginTop: 10 }}>
                      <button type="button" className={styles.submitButton} onClick={() => onSavePolicy("backup")}>
                        Salvar politica backup
                      </button>
                      <button type="button" className={styles.submitButton} onClick={() => onSavePolicy("snapshot")}>
                        Salvar politica snapshot
                      </button>
                      <button type="button" className={styles.smallButton} onClick={() => onRunNow("backup")}>
                        Executar backup agora
                      </button>
                      <button type="button" className={styles.smallButton} onClick={() => onRunNow("snapshot")}>
                        Executar snapshot agora
                      </button>
                    </div>
                  </>
                ) : null}

                {hetznerError ? <p className={styles.statusError}>{hetznerError}</p> : null}
                {hetznerMessage ? <p className={styles.statusOk}>{hetznerMessage}</p> : null}
              </article>

              <article className={styles.panel}>
                {hetznerSection === "cadastro" ? (
                  <>
                    <h2 className={styles.panelTitle}>Servidores Hetzner</h2>
                    {loadingHetzner ? <p className={styles.sub}>Carregando...</p> : null}
                    <div className={styles.tableWrap}>
                      <table className={styles.table}>
                        <thead>
                          <tr>
                            <th>Nome</th>
                            <th>External ID</th>
                            <th>Datacenter</th>
                            <th>IPv4</th>
                            <th>Allow Backup</th>
                            <th>Allow Snapshot</th>
                          </tr>
                        </thead>
                        <tbody>
                          {hetznerServers.map((srv) => (
                            <tr key={srv.id}>
                              <td>{srv.name}</td>
                              <td>{srv.external_id}</td>
                              <td>{srv.datacenter ?? "-"}</td>
                              <td>{srv.ipv4 ?? "-"}</td>
                              <td>
                                <input
                                  type="checkbox"
                                  checked={srv.allow_backup}
                                  onChange={(e) => onToggleServerAllow(srv, "allow_backup", e.target.checked)}
                                />
                              </td>
                              <td>
                                <input
                                  type="checkbox"
                                  checked={srv.allow_snapshot}
                                  onChange={(e) => onToggleServerAllow(srv, "allow_snapshot", e.target.checked)}
                                />
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </>
                ) : null}

                {hetznerSection === "servicos" ? (
                  <>
                    <h2 className={styles.panelTitle}>Politicas do servidor</h2>
                    <div className={styles.tableWrap}>
                      <table className={styles.table}>
                        <thead>
                          <tr>
                            <th>Servico</th>
                            <th>Enabled</th>
                            <th>Confirmacao</th>
                            <th>Agendamento</th>
                            <th>Retencao</th>
                            <th>Ultima execucao</th>
                            <th>Status</th>
                          </tr>
                        </thead>
                        <tbody>
                          {hetznerPolicies.map((policy) => (
                            <tr key={policy.id}>
                              <td>{policy.service_type}</td>
                              <td>{policy.enabled ? "sim" : "nao"}</td>
                              <td>{policy.require_confirmation ? "sim" : "nao"}</td>
                              <td>{policy.schedule_mode === "interval" ? `interval ${policy.interval_minutes ?? "-"} min` : "manual"}</td>
                              <td>{`dias=${policy.retention_days ?? "-"} qtd=${policy.retention_count ?? "-"}`}</td>
                              <td>{policy.last_run_at ?? "-"}</td>
                              <td>{policy.last_status ?? "-"}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </>
                ) : null}

                {hetznerSection === "alertas" ? (
                  <>
                    <h2 className={styles.panelTitle}>Alertas Hetzner</h2>
                    <div className={styles.tableWrap}>
                      <table className={styles.table}>
                        <thead>
                          <tr>
                            <th>Servidor</th>
                            <th>Servico</th>
                            <th>Status</th>
                            <th>Detalhe</th>
                            <th>Ultima execucao</th>
                          </tr>
                        </thead>
                        <tbody>
                          {hetznerAlerts.map((item, idx) => (
                            <tr key={`${item.server_id}-${item.service_type}-${idx}`}>
                              <td>{item.server_name}</td>
                              <td>{item.service_type}</td>
                              <td>{item.status}</td>
                              <td>{item.details}</td>
                              <td>{item.last_run_at ?? "-"}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </>
                ) : null}

                {hetznerSection === "logs" ? (
                  <>
                    <h2 className={styles.panelTitle}>Logs Hetzner</h2>
                    <div className={styles.tableWrap}>
                      <table className={styles.table}>
                        <thead>
                          <tr>
                            <th>Quando</th>
                            <th>Server ID</th>
                            <th>Servico</th>
                            <th>Acao</th>
                            <th>Status</th>
                            <th>Mensagem</th>
                          </tr>
                        </thead>
                        <tbody>
                          {hetznerLogs.map((log) => (
                            <tr key={log.id}>
                              <td>{log.created_at}</td>
                              <td>{log.server_id}</td>
                              <td>{log.service_type}</td>
                              <td>{log.action}</td>
                              <td>{log.status}</td>
                              <td>{log.message}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </>
                ) : null}

                {hetznerSection !== "alertas" && hetznerSection !== "logs" ? (
                  <p className={styles.sub} style={{ marginTop: 12 }}>
                    Statuss atuais: {hetznerStatus.filter((s) => s.status === "ok").length} OK,{" "}
                    {hetznerStatus.filter((s) => s.status === "atraso").length} atraso,{" "}
                    {hetznerStatus.filter((s) => s.status === "falha").length} falha,{" "}
                    {hetznerStatus.filter((s) => s.status === "sem_politica").length} sem politica,{" "}
                    {hetznerStatus.filter((s) => s.status === "pausado").length} pausado
                  </p>
                ) : null}
              </article>
            </section>
          )}
        </main>
      </section>
    </div>
  );
}
