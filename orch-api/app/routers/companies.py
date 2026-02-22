from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.secrets import encrypt_secret
from app.deps import AuthContext, get_db, require_roles
from app.models import ApiCredential, Company, HetznerServer
from app.schemas import (
    ApiCredentialCreateRequest,
    ApiCredentialOut,
    ApiCredentialUpdateRequest,
    CompanyCreateRequest,
    CompanyOut,
    CompanyUpdateRequest,
)
from app.services.audit import write_audit

router = APIRouter(tags=["companies"])


def _serialize_credential(row: ApiCredential) -> ApiCredentialOut:
    return ApiCredentialOut(
        id=row.id,
        company_id=row.company_id,
        provider=row.provider,
        label=row.label,
        base_url=row.base_url,
        account_id=row.account_id,
        metadata_json=row.metadata_json or {},
        secret_present=bool(row.secret_encrypted),
        created_at=row.created_at,
        updated_at=row.updated_at,
    )


@router.get("/companies", response_model=list[CompanyOut])
def list_companies(
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin")),
):
    rows = db.execute(select(Company).order_by(Company.name.asc())).scalars().all()
    return rows


@router.post("/companies", response_model=CompanyOut)
def create_company(
    payload: CompanyCreateRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    name = payload.name.strip()
    if not name:
        raise HTTPException(status_code=400, detail="Company name is required")

    exists = db.execute(select(Company).where(Company.name == name)).scalar_one_or_none()
    if exists:
        raise HTTPException(status_code=409, detail="Company already exists")

    row = Company(name=name, status=payload.status)
    db.add(row)
    db.commit()
    db.refresh(row)
    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="company.create",
        target_type="company",
        target_id=str(row.id),
        metadata_json={"name": row.name},
    )
    return row


@router.patch("/companies/{company_id}", response_model=CompanyOut)
def update_company(
    company_id: int,
    payload: CompanyUpdateRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    row = db.get(Company, company_id)
    if not row:
        raise HTTPException(status_code=404, detail="Company not found")

    if payload.name is not None:
        name = payload.name.strip()
        if not name:
            raise HTTPException(status_code=400, detail="Company name cannot be empty")
        conflict = db.execute(
            select(Company).where(Company.name == name, Company.id != company_id)
        ).scalar_one_or_none()
        if conflict:
            raise HTTPException(status_code=409, detail="Company name already exists")
        row.name = name

    if payload.status is not None:
        row.status = payload.status

    db.commit()
    db.refresh(row)
    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="company.update",
        target_type="company",
        target_id=str(row.id),
        metadata_json={"name": row.name},
    )
    return row


@router.get("/companies/{company_id}/api-credentials", response_model=list[ApiCredentialOut])
def list_company_credentials(
    company_id: int,
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin")),
):
    company = db.get(Company, company_id)
    if not company:
        raise HTTPException(status_code=404, detail="Company not found")

    rows = (
        db.execute(
            select(ApiCredential)
            .where(ApiCredential.company_id == company_id)
            .order_by(ApiCredential.provider.asc(), ApiCredential.label.asc())
        )
        .scalars()
        .all()
    )
    return [_serialize_credential(row) for row in rows]


@router.post("/companies/{company_id}/api-credentials", response_model=ApiCredentialOut)
def create_company_credential(
    company_id: int,
    payload: ApiCredentialCreateRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    company = db.get(Company, company_id)
    if not company:
        raise HTTPException(status_code=404, detail="Company not found")

    if not payload.secret_value.strip():
        raise HTTPException(status_code=400, detail="Secret value is required")

    row = ApiCredential(
        company_id=company_id,
        provider=payload.provider.strip().lower(),
        label=payload.label.strip(),
        base_url=(payload.base_url or "").strip() or None,
        account_id=(payload.account_id or "").strip() or None,
        metadata_json=payload.metadata_json,
        secret_encrypted=encrypt_secret(payload.secret_value.strip()),
    )
    db.add(row)
    db.commit()
    db.refresh(row)
    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="api_credential.create",
        target_type="api_credential",
        target_id=str(row.id),
        metadata_json={"company_id": company_id, "provider": row.provider, "label": row.label},
    )
    return _serialize_credential(row)


@router.patch("/api-credentials/{credential_id}", response_model=ApiCredentialOut)
def update_credential(
    credential_id: int,
    payload: ApiCredentialUpdateRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    row = db.get(ApiCredential, credential_id)
    if not row:
        raise HTTPException(status_code=404, detail="Credential not found")

    if payload.provider is not None:
        row.provider = payload.provider.strip().lower()
    if payload.label is not None:
        row.label = payload.label.strip()
    if payload.base_url is not None:
        row.base_url = payload.base_url.strip() or None
    if payload.account_id is not None:
        row.account_id = payload.account_id.strip() or None
    if payload.metadata_json is not None:
        row.metadata_json = payload.metadata_json
    if payload.secret_value is not None:
        secret = payload.secret_value.strip()
        if not secret:
            raise HTTPException(status_code=400, detail="Secret value cannot be empty")
        row.secret_encrypted = encrypt_secret(secret)

    db.commit()
    db.refresh(row)
    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="api_credential.update",
        target_type="api_credential",
        target_id=str(row.id),
        metadata_json={"provider": row.provider, "label": row.label},
    )
    return _serialize_credential(row)


@router.delete("/api-credentials/{credential_id}")
def delete_credential(
    credential_id: int,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    row = db.get(ApiCredential, credential_id)
    if not row:
        raise HTTPException(status_code=404, detail="Credential not found")

    used_by = (
        db.execute(select(HetznerServer).where(HetznerServer.credential_id == credential_id))
        .scalars()
        .first()
    )
    if used_by:
        raise HTTPException(
            status_code=409,
            detail="Credential is linked to servers. Reassign or unlink servers before deleting.",
        )

    provider = row.provider
    label = row.label
    db.delete(row)
    db.commit()
    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="api_credential.delete",
        target_type="api_credential",
        target_id=str(credential_id),
        metadata_json={"provider": provider, "label": label},
    )
    return {"ok": True}
