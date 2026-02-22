import json
from datetime import datetime, timedelta, timezone
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.secrets import decrypt_secret
from app.deps import AuthContext, get_db, require_roles
from app.models import ApiCredential, Company, HetznerServer, HetznerServiceLog, HetznerServicePolicy
from app.schemas import (
    HetznerImportRequest,
    HetznerServerCreateRequest,
    HetznerServerOut,
    HetznerServerStatusOut,
    HetznerServerUpdateRequest,
    HetznerServiceLogOut,
    HetznerServicePolicyOut,
    HetznerServicePolicyUpsertRequest,
    HetznerServiceRunRequest,
)
from app.services.audit import write_audit

router = APIRouter(tags=["hetzner"])
HETZNER_API_BASE = "https://api.hetzner.cloud/v1"
SERVICE_TYPES = {"backup", "snapshot"}


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


def _get_company_or_404(db: Session, company_id: int) -> Company:
    company = db.get(Company, company_id)
    if not company:
        raise HTTPException(status_code=404, detail="Company not found")
    return company


def _serialize_server(row: HetznerServer) -> HetznerServerOut:
    return HetznerServerOut(
        id=row.id,
        company_id=row.company_id,
        credential_id=row.credential_id,
        external_id=row.external_id,
        name=row.name,
        datacenter=row.datacenter,
        ipv4=row.ipv4,
        labels_json=row.labels_json or {},
        status=row.status,
        allow_backup=row.allow_backup,
        allow_snapshot=row.allow_snapshot,
        created_at=row.created_at,
        updated_at=row.updated_at,
    )


def _serialize_policy(row: HetznerServicePolicy) -> HetznerServicePolicyOut:
    return HetznerServicePolicyOut(
        id=row.id,
        server_id=row.server_id,
        service_type=row.service_type,
        enabled=row.enabled,
        require_confirmation=row.require_confirmation,
        schedule_mode=row.schedule_mode,
        interval_minutes=row.interval_minutes,
        retention_days=row.retention_days,
        retention_count=row.retention_count,
        last_run_at=row.last_run_at,
        next_run_at=row.next_run_at,
        last_status=row.last_status,
        last_error=row.last_error,
        created_at=row.created_at,
        updated_at=row.updated_at,
    )


def _serialize_log(row: HetznerServiceLog) -> HetznerServiceLogOut:
    return HetznerServiceLogOut(
        id=row.id,
        server_id=row.server_id,
        service_type=row.service_type,
        action=row.action,
        status=row.status,
        message=row.message,
        created_by=row.created_by,
        created_at=row.created_at,
    )


def _ensure_service_type(service_type: str) -> str:
    normalized = service_type.strip().lower()
    if normalized not in SERVICE_TYPES:
        raise HTTPException(status_code=400, detail="Invalid service_type")
    return normalized


def _policy_status(policy: HetznerServicePolicy | None) -> tuple[str, str]:
    if not policy:
        return "sem_politica", "Servico sem politica configurada"
    if not policy.enabled:
        return "pausado", "Politica desativada"
    if policy.last_status == "failed":
        return "falha", policy.last_error or "Ultima execucao falhou"
    if policy.last_run_at is None:
        return "atraso", "Nunca executado"
    if policy.schedule_mode == "interval" and policy.interval_minutes:
        deadline = policy.last_run_at + timedelta(minutes=max(policy.interval_minutes, 1))
        if _utcnow() > deadline:
            return "atraso", "Execucao atrasada"
    return "ok", "Execucao em dia"


def _fetch_hetzner_servers(token: str) -> list[dict]:
    request = Request(
        f"{HETZNER_API_BASE}/servers",
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
        method="GET",
    )
    try:
        with urlopen(request, timeout=20) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        body = exc.read().decode("utf-8", errors="ignore")
        raise HTTPException(status_code=400, detail=f"Hetzner API error: {exc.code} {body[:300]}")
    except URLError as exc:
        raise HTTPException(status_code=400, detail=f"Hetzner API unreachable: {exc.reason}")
    return payload.get("servers", [])


def _hetzner_action(token: str, server_external_id: str, action_path: str, payload: dict | None = None) -> dict:
    body = json.dumps(payload or {}).encode("utf-8")
    request = Request(
        f"{HETZNER_API_BASE}/servers/{server_external_id}/actions/{action_path}",
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
        method="POST",
        data=body,
    )
    try:
        with urlopen(request, timeout=20) as response:
            return json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        body = exc.read().decode("utf-8", errors="ignore")
        raise HTTPException(status_code=400, detail=f"Hetzner action error: {exc.code} {body[:300]}")
    except URLError as exc:
        raise HTTPException(status_code=400, detail=f"Hetzner action unreachable: {exc.reason}")


def _ensure_default_policies(db: Session, server_id: int) -> None:
    for service in ("backup", "snapshot"):
        exists = db.execute(
            select(HetznerServicePolicy).where(
                HetznerServicePolicy.server_id == server_id,
                HetznerServicePolicy.service_type == service,
            )
        ).scalar_one_or_none()
        if not exists:
            db.add(
                HetznerServicePolicy(
                    server_id=server_id,
                    service_type=service,
                    enabled=False,
                    require_confirmation=True,
                    schedule_mode="manual",
                )
            )
    db.commit()


@router.get("/companies/{company_id}/hetzner/servers", response_model=list[HetznerServerOut])
def list_hetzner_servers(
    company_id: int,
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin")),
):
    _get_company_or_404(db, company_id)
    rows = (
        db.execute(select(HetznerServer).where(HetznerServer.company_id == company_id).order_by(HetznerServer.name.asc()))
        .scalars()
        .all()
    )
    return [_serialize_server(row) for row in rows]


@router.post("/companies/{company_id}/hetzner/servers", response_model=HetznerServerOut)
def create_hetzner_server(
    company_id: int,
    payload: HetznerServerCreateRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    _get_company_or_404(db, company_id)
    external_id = payload.external_id.strip()
    if not external_id:
        raise HTTPException(status_code=400, detail="external_id is required")

    conflict = db.execute(
        select(HetznerServer).where(HetznerServer.company_id == company_id, HetznerServer.external_id == external_id)
    ).scalar_one_or_none()
    if conflict:
        raise HTTPException(status_code=409, detail="Server already exists for this company")

    row = HetznerServer(
        company_id=company_id,
        credential_id=payload.credential_id,
        external_id=external_id,
        name=payload.name.strip(),
        datacenter=(payload.datacenter or "").strip() or None,
        ipv4=(payload.ipv4 or "").strip() or None,
        labels_json=payload.labels_json or {},
        status="active",
    )
    db.add(row)
    db.commit()
    db.refresh(row)
    _ensure_default_policies(db, row.id)
    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="hetzner.server.create",
        target_type="hetzner_server",
        target_id=str(row.id),
        metadata_json={"company_id": company_id, "external_id": row.external_id, "name": row.name},
    )
    return _serialize_server(row)


@router.post("/companies/{company_id}/hetzner/import", response_model=list[HetznerServerOut])
def import_hetzner_servers(
    company_id: int,
    payload: HetznerImportRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    _get_company_or_404(db, company_id)
    credential = db.get(ApiCredential, payload.credential_id)
    if not credential or credential.company_id != company_id:
        raise HTTPException(status_code=404, detail="Credential not found for company")
    if credential.provider != "hetzner":
        raise HTTPException(status_code=400, detail="Credential provider must be hetzner")

    token = decrypt_secret(credential.secret_encrypted)
    servers = _fetch_hetzner_servers(token)

    upserted: list[HetznerServer] = []
    for srv in servers:
        external_id = str(srv.get("id"))
        if not external_id:
            continue
        row = db.execute(
            select(HetznerServer).where(
                HetznerServer.company_id == company_id,
                HetznerServer.external_id == external_id,
            )
        ).scalar_one_or_none()
        ipv4 = ((srv.get("public_net") or {}).get("ipv4") or {}).get("ip")
        datacenter = ((srv.get("datacenter") or {}).get("name")) or None
        if row:
            row.name = srv.get("name") or row.name
            row.datacenter = datacenter
            row.ipv4 = ipv4
            row.labels_json = srv.get("labels") or {}
            row.credential_id = credential.id
        else:
            row = HetznerServer(
                company_id=company_id,
                credential_id=credential.id,
                external_id=external_id,
                name=srv.get("name") or f"server-{external_id}",
                datacenter=datacenter,
                ipv4=ipv4,
                labels_json=srv.get("labels") or {},
                status="active",
            )
            db.add(row)
        upserted.append(row)

    db.commit()
    for row in upserted:
        db.refresh(row)
        _ensure_default_policies(db, row.id)

    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="hetzner.server.import",
        target_type="company",
        target_id=str(company_id),
        metadata_json={"credential_id": credential.id, "imported_count": len(upserted)},
    )
    return [_serialize_server(row) for row in upserted]


@router.patch("/hetzner/servers/{server_id}", response_model=HetznerServerOut)
def update_hetzner_server(
    server_id: int,
    payload: HetznerServerUpdateRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    row = db.get(HetznerServer, server_id)
    if not row:
        raise HTTPException(status_code=404, detail="Server not found")

    if "credential_id" in payload.model_fields_set:
        if payload.credential_id is None:
            row.credential_id = None
        else:
            credential = db.get(ApiCredential, payload.credential_id)
            if not credential or credential.company_id != row.company_id:
                raise HTTPException(status_code=404, detail="Credential not found for server company")
            if credential.provider != "hetzner":
                raise HTTPException(status_code=400, detail="Credential provider must be hetzner")
            row.credential_id = credential.id

    if payload.name is not None:
        row.name = payload.name.strip() or row.name
    if payload.datacenter is not None:
        row.datacenter = payload.datacenter.strip() or None
    if payload.ipv4 is not None:
        row.ipv4 = payload.ipv4.strip() or None
    if payload.labels_json is not None:
        row.labels_json = payload.labels_json
    if payload.status is not None:
        row.status = payload.status
    if payload.allow_backup is not None:
        row.allow_backup = payload.allow_backup
    if payload.allow_snapshot is not None:
        row.allow_snapshot = payload.allow_snapshot

    db.commit()
    db.refresh(row)
    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="hetzner.server.update",
        target_type="hetzner_server",
        target_id=str(row.id),
        metadata_json={"name": row.name, "allow_backup": row.allow_backup, "allow_snapshot": row.allow_snapshot},
    )
    return _serialize_server(row)


@router.get("/hetzner/servers/{server_id}/services", response_model=list[HetznerServicePolicyOut])
def list_server_policies(
    server_id: int,
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin")),
):
    server = db.get(HetznerServer, server_id)
    if not server:
        raise HTTPException(status_code=404, detail="Server not found")
    _ensure_default_policies(db, server_id)
    rows = (
        db.execute(
            select(HetznerServicePolicy)
            .where(HetznerServicePolicy.server_id == server_id)
            .order_by(HetznerServicePolicy.service_type.asc())
        )
        .scalars()
        .all()
    )
    return [_serialize_policy(row) for row in rows]


@router.put("/hetzner/servers/{server_id}/services/{service_type}", response_model=HetznerServicePolicyOut)
def upsert_server_policy(
    server_id: int,
    service_type: str,
    payload: HetznerServicePolicyUpsertRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    normalized_service = _ensure_service_type(service_type)
    server = db.get(HetznerServer, server_id)
    if not server:
        raise HTTPException(status_code=404, detail="Server not found")

    row = db.execute(
        select(HetznerServicePolicy).where(
            HetznerServicePolicy.server_id == server_id,
            HetznerServicePolicy.service_type == normalized_service,
        )
    ).scalar_one_or_none()
    if not row:
        row = HetznerServicePolicy(server_id=server_id, service_type=normalized_service)
        db.add(row)

    row.enabled = payload.enabled
    row.require_confirmation = payload.require_confirmation
    row.schedule_mode = payload.schedule_mode
    row.interval_minutes = payload.interval_minutes
    row.retention_days = payload.retention_days
    row.retention_count = payload.retention_count
    row.next_run_at = None
    if payload.schedule_mode == "interval" and payload.interval_minutes:
        row.next_run_at = _utcnow() + timedelta(minutes=max(payload.interval_minutes, 1))

    db.add(
        HetznerServiceLog(
            server_id=server_id,
            service_type=normalized_service,
            action="policy_update",
            status="ok",
            message=(
                f"Policy updated: enabled={row.enabled}, schedule_mode={row.schedule_mode}, "
                f"interval_minutes={row.interval_minutes}, retention_days={row.retention_days}, "
                f"retention_count={row.retention_count}"
            ),
            created_by=ctx.user.id,
        )
    )

    db.commit()
    db.refresh(row)
    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="hetzner.policy.upsert",
        target_type="hetzner_policy",
        target_id=str(row.id),
        metadata_json={"server_id": server_id, "service_type": row.service_type},
    )
    return _serialize_policy(row)


@router.post("/hetzner/servers/{server_id}/services/{service_type}/run", response_model=HetznerServicePolicyOut)
def run_service_now(
    server_id: int,
    service_type: str,
    payload: HetznerServiceRunRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    normalized_service = _ensure_service_type(service_type)
    server = db.get(HetznerServer, server_id)
    if not server:
        raise HTTPException(status_code=404, detail="Server not found")

    policy = db.execute(
        select(HetznerServicePolicy).where(
            HetznerServicePolicy.server_id == server_id,
            HetznerServicePolicy.service_type == normalized_service,
        )
    ).scalar_one_or_none()
    if not policy:
        raise HTTPException(status_code=400, detail="Service policy not configured")
    if not policy.enabled:
        raise HTTPException(status_code=400, detail="Service policy is paused")
    if normalized_service == "backup" and not server.allow_backup:
        raise HTTPException(status_code=400, detail="Backup execution not allowed for this server")
    if normalized_service == "snapshot" and not server.allow_snapshot:
        raise HTTPException(status_code=400, detail="Snapshot execution not allowed for this server")
    if policy.require_confirmation and not payload.confirm:
        raise HTTPException(status_code=400, detail="Execution requires confirm=true")
    if not server.credential_id:
        raise HTTPException(status_code=400, detail="Server has no Hetzner credential associated")

    credential = db.get(ApiCredential, server.credential_id)
    if not credential or credential.provider != "hetzner":
        raise HTTPException(status_code=400, detail="Invalid Hetzner credential")

    token = decrypt_secret(credential.secret_encrypted)
    action_label = ""
    if normalized_service == "snapshot":
        _hetzner_action(
            token,
            server.external_id,
            "create_image",
            {"type": "snapshot", "description": f"omniforge-{server.name}-{int(_utcnow().timestamp())}"},
        )
        action_label = "Snapshot created via Hetzner create_image"
    else:
        _hetzner_action(token, server.external_id, "enable_backup", {})
        action_label = "Automatic backup enabled via Hetzner enable_backup"

    policy.last_run_at = _utcnow()
    policy.last_status = "success"
    policy.last_error = None
    if policy.schedule_mode == "interval" and policy.interval_minutes:
        policy.next_run_at = policy.last_run_at + timedelta(minutes=max(policy.interval_minutes, 1))

    db.add(
        HetznerServiceLog(
            server_id=server_id,
            service_type=normalized_service,
            action="run_now",
            status="ok",
            message=action_label,
            created_by=ctx.user.id,
        )
    )

    db.commit()
    db.refresh(policy)
    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="hetzner.service.run",
        target_type="hetzner_policy",
        target_id=str(policy.id),
        metadata_json={"server_id": server.id, "service_type": normalized_service},
    )
    return _serialize_policy(policy)


@router.get("/companies/{company_id}/hetzner/logs", response_model=list[HetznerServiceLogOut])
def list_hetzner_logs(
    company_id: int,
    limit: int = Query(default=100, ge=1, le=500),
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin")),
):
    _get_company_or_404(db, company_id)
    rows = (
        db.execute(
            select(HetznerServiceLog)
            .join(HetznerServer, HetznerServer.id == HetznerServiceLog.server_id)
            .where(HetznerServer.company_id == company_id)
            .order_by(HetznerServiceLog.created_at.desc())
            .limit(limit)
        )
        .scalars()
        .all()
    )
    return [_serialize_log(row) for row in rows]


@router.get("/companies/{company_id}/hetzner/status", response_model=list[HetznerServerStatusOut])
def list_hetzner_status(
    company_id: int,
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin")),
):
    _get_company_or_404(db, company_id)
    servers = (
        db.execute(select(HetznerServer).where(HetznerServer.company_id == company_id).order_by(HetznerServer.name.asc()))
        .scalars()
        .all()
    )

    results: list[HetznerServerStatusOut] = []
    for server in servers:
        for service in ("backup", "snapshot"):
            policy = db.execute(
                select(HetznerServicePolicy).where(
                    HetznerServicePolicy.server_id == server.id,
                    HetznerServicePolicy.service_type == service,
                )
            ).scalar_one_or_none()
            status, details = _policy_status(policy)
            results.append(
                HetznerServerStatusOut(
                    server_id=server.id,
                    server_name=server.name,
                    service_type=service,
                    status=status,
                    details=details,
                    next_run_at=policy.next_run_at if policy else None,
                    last_run_at=policy.last_run_at if policy else None,
                )
            )
    return results


@router.get("/companies/{company_id}/hetzner/alerts", response_model=list[HetznerServerStatusOut])
def list_hetzner_alerts(
    company_id: int,
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin")),
):
    statuses = list_hetzner_status(company_id=company_id, db=db)
    return [item for item in statuses if item.status in {"atraso", "falha", "sem_politica"}]
