from datetime import datetime
from typing import Any

from pydantic import BaseModel, Field


class TokenPair(BaseModel):
    access_token: str
    refresh_token: str
    token_type: str = "bearer"


class LoginRequest(BaseModel):
    email: str
    password: str


class RefreshRequest(BaseModel):
    refresh_token: str


class MeResponse(BaseModel):
    id: int
    email: str
    status: str
    roles: list[str]
    areas: list[str]


class AreaCreateRequest(BaseModel):
    name: str


class AreaOut(BaseModel):
    id: int
    name: str

    model_config = {"from_attributes": True}


class UserOut(BaseModel):
    id: int
    email: str
    status: str
    created_at: datetime
    roles: list[str]
    areas: list[str]


class UserCreateRequest(BaseModel):
    email: str
    password: str
    roles: list[str] = Field(default_factory=list)
    areas: list[str] = Field(default_factory=list)
    status: str = "active"


class UserUpdateRequest(BaseModel):
    password: str | None = None
    status: str | None = None
    roles: list[str] | None = None
    areas: list[str] | None = None


class CompanyCreateRequest(BaseModel):
    name: str
    status: str = "active"


class CompanyUpdateRequest(BaseModel):
    name: str | None = None
    status: str | None = None


class CompanyOut(BaseModel):
    id: int
    name: str
    status: str
    created_at: datetime

    model_config = {"from_attributes": True}


class ApiCredentialCreateRequest(BaseModel):
    provider: str
    label: str
    base_url: str | None = None
    account_id: str | None = None
    metadata_json: dict[str, Any] = Field(default_factory=dict)
    secret_value: str


class ApiCredentialUpdateRequest(BaseModel):
    provider: str | None = None
    label: str | None = None
    base_url: str | None = None
    account_id: str | None = None
    metadata_json: dict[str, Any] | None = None
    secret_value: str | None = None


class ApiCredentialOut(BaseModel):
    id: int
    company_id: int
    provider: str
    label: str
    base_url: str | None
    account_id: str | None
    metadata_json: dict[str, Any]
    secret_present: bool
    created_at: datetime
    updated_at: datetime


class HetznerServerCreateRequest(BaseModel):
    credential_id: int | None = None
    external_id: str
    name: str
    datacenter: str | None = None
    ipv4: str | None = None
    labels_json: dict[str, Any] = Field(default_factory=dict)


class HetznerServerUpdateRequest(BaseModel):
    credential_id: int | None = None
    name: str | None = None
    datacenter: str | None = None
    ipv4: str | None = None
    labels_json: dict[str, Any] | None = None
    status: str | None = None
    allow_backup: bool | None = None
    allow_snapshot: bool | None = None


class HetznerServerOut(BaseModel):
    id: int
    company_id: int
    credential_id: int | None
    external_id: str
    name: str
    datacenter: str | None
    ipv4: str | None
    labels_json: dict[str, Any]
    status: str
    allow_backup: bool
    allow_snapshot: bool
    created_at: datetime
    updated_at: datetime


class HetznerImportRequest(BaseModel):
    credential_id: int


class HetznerServicePolicyUpsertRequest(BaseModel):
    enabled: bool
    require_confirmation: bool = True
    schedule_mode: str = "manual"
    interval_minutes: int | None = None
    retention_days: int | None = None
    retention_count: int | None = None


class HetznerServicePolicyOut(BaseModel):
    id: int
    server_id: int
    service_type: str
    enabled: bool
    require_confirmation: bool
    schedule_mode: str
    interval_minutes: int | None
    retention_days: int | None
    retention_count: int | None
    last_run_at: datetime | None
    next_run_at: datetime | None
    last_status: str | None
    last_error: str | None
    created_at: datetime
    updated_at: datetime


class HetznerServiceRunRequest(BaseModel):
    confirm: bool = False


class HetznerServiceLogOut(BaseModel):
    id: int
    server_id: int
    service_type: str
    action: str
    status: str
    message: str
    created_by: int | None
    created_at: datetime


class HetznerServerStatusOut(BaseModel):
    server_id: int
    server_name: str
    service_type: str
    status: str
    details: str
    next_run_at: datetime | None
    last_run_at: datetime | None


class RunbookOut(BaseModel):
    name: str
    version: str
    category: str
    enabled: bool
    schema_definition: dict[str, Any] = Field(
        alias="schema_json",
        serialization_alias="schema_json",
    )

    model_config = {"from_attributes": True, "populate_by_name": True}


class ExecuteRunbookRequest(BaseModel):
    inputs: dict[str, Any] = Field(default_factory=dict)


class ExecuteRunbookResponse(BaseModel):
    job_id: int
    status: str


class JobOut(BaseModel):
    id: int
    runbook_name: str
    status: str
    input_json: dict[str, Any]
    output_json: dict[str, Any] | None
    created_by: int
    started_at: datetime | None
    finished_at: datetime | None
    created_at: datetime

    model_config = {"from_attributes": True}


class JobsListResponse(BaseModel):
    items: list[JobOut]
    total: int
    page: int
    page_size: int


class JobLogOut(BaseModel):
    id: int
    job_id: int
    ts: datetime
    level: str
    message: str

    model_config = {"from_attributes": True}
