from datetime import datetime, timezone

from sqlalchemy import JSON, Boolean, Column, DateTime, ForeignKey, Integer, String, Text, UniqueConstraint
from sqlalchemy.orm import relationship

from app.db import Base


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


class UserRole(Base):
    __tablename__ = "user_roles"

    user_id = Column(Integer, ForeignKey("users.id"), primary_key=True)
    role_id = Column(Integer, ForeignKey("roles.id"), primary_key=True)


class UserArea(Base):
    __tablename__ = "user_areas"

    user_id = Column(Integer, ForeignKey("users.id"), primary_key=True)
    area_id = Column(Integer, ForeignKey("access_areas.id"), primary_key=True)


class Role(Base):
    __tablename__ = "roles"

    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(50), unique=True, nullable=False, index=True)
    users = relationship("User", secondary="user_roles", back_populates="roles")


class User(Base):
    __tablename__ = "users"

    id = Column(Integer, primary_key=True, index=True)
    email = Column(String(255), unique=True, nullable=False, index=True)
    password_hash = Column(String(255), nullable=False)
    status = Column(String(20), default="active", nullable=False)
    created_at = Column(DateTime(timezone=True), default=utcnow, nullable=False)
    roles = relationship("Role", secondary="user_roles", back_populates="users")
    areas = relationship("AccessArea", secondary="user_areas", back_populates="users")


class AccessArea(Base):
    __tablename__ = "access_areas"

    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(80), unique=True, nullable=False, index=True)
    users = relationship("User", secondary="user_areas", back_populates="areas")


class Runbook(Base):
    __tablename__ = "runbooks"

    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(255), unique=True, nullable=False, index=True)
    version = Column(String(20), default="1.0.0", nullable=False)
    schema_json = Column(JSON, nullable=False)
    category = Column(String(80), nullable=False, default="general")
    enabled = Column(Boolean, nullable=False, default=True)
    created_at = Column(DateTime(timezone=True), default=utcnow, nullable=False)


class Job(Base):
    __tablename__ = "jobs"

    id = Column(Integer, primary_key=True, index=True)
    runbook_name = Column(String(255), nullable=False, index=True)
    status = Column(String(20), nullable=False, default="PENDING", index=True)
    input_json = Column(JSON, nullable=False, default=dict)
    output_json = Column(JSON, nullable=True)
    created_by = Column(Integer, ForeignKey("users.id"), nullable=False)
    started_at = Column(DateTime(timezone=True), nullable=True)
    finished_at = Column(DateTime(timezone=True), nullable=True)
    created_at = Column(DateTime(timezone=True), default=utcnow, nullable=False)

    logs = relationship("JobLog", back_populates="job", cascade="all, delete-orphan")


class JobLog(Base):
    __tablename__ = "job_logs"

    id = Column(Integer, primary_key=True, index=True)
    job_id = Column(Integer, ForeignKey("jobs.id"), nullable=False, index=True)
    ts = Column(DateTime(timezone=True), default=utcnow, nullable=False, index=True)
    level = Column(String(20), nullable=False, default="INFO")
    message = Column(Text, nullable=False)

    job = relationship("Job", back_populates="logs")


class AuditLog(Base):
    __tablename__ = "audit_log"

    id = Column(Integer, primary_key=True, index=True)
    actor_user_id = Column(Integer, ForeignKey("users.id"), nullable=True, index=True)
    action = Column(String(120), nullable=False, index=True)
    target_type = Column(String(80), nullable=False, index=True)
    target_id = Column(String(120), nullable=True)
    ts = Column(DateTime(timezone=True), default=utcnow, nullable=False, index=True)
    metadata_json = Column(JSON, nullable=True)


class Company(Base):
    __tablename__ = "companies"

    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(255), unique=True, nullable=False, index=True)
    status = Column(String(20), nullable=False, default="active")
    created_at = Column(DateTime(timezone=True), default=utcnow, nullable=False)

    credentials = relationship(
        "ApiCredential",
        back_populates="company",
        cascade="all, delete-orphan",
    )


class ApiCredential(Base):
    __tablename__ = "api_credentials"

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id"), nullable=False, index=True)
    provider = Column(String(80), nullable=False, index=True)
    label = Column(String(255), nullable=False)
    base_url = Column(String(500), nullable=True)
    account_id = Column(String(255), nullable=True)
    metadata_json = Column(JSON, nullable=False, default=dict)
    secret_encrypted = Column(Text, nullable=False)
    created_at = Column(DateTime(timezone=True), default=utcnow, nullable=False)
    updated_at = Column(DateTime(timezone=True), default=utcnow, onupdate=utcnow, nullable=False)

    company = relationship("Company", back_populates="credentials")


class HetznerServer(Base):
    __tablename__ = "hetzner_servers"
    __table_args__ = (UniqueConstraint("company_id", "external_id", name="uq_hetzner_server_company_external"),)

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id"), nullable=False, index=True)
    credential_id = Column(Integer, ForeignKey("api_credentials.id"), nullable=True, index=True)
    external_id = Column(String(64), nullable=False, index=True)
    name = Column(String(255), nullable=False, index=True)
    datacenter = Column(String(120), nullable=True)
    ipv4 = Column(String(80), nullable=True)
    labels_json = Column(JSON, nullable=False, default=dict)
    status = Column(String(20), nullable=False, default="active")
    allow_backup = Column(Boolean, nullable=False, default=False)
    allow_snapshot = Column(Boolean, nullable=False, default=False)
    created_at = Column(DateTime(timezone=True), default=utcnow, nullable=False)
    updated_at = Column(DateTime(timezone=True), default=utcnow, onupdate=utcnow, nullable=False)

    company = relationship("Company")
    credential = relationship("ApiCredential")
    policies = relationship("HetznerServicePolicy", back_populates="server", cascade="all, delete-orphan")
    logs = relationship("HetznerServiceLog", back_populates="server", cascade="all, delete-orphan")


class HetznerServicePolicy(Base):
    __tablename__ = "hetzner_service_policies"
    __table_args__ = (UniqueConstraint("server_id", "service_type", name="uq_hetzner_policy_server_service"),)

    id = Column(Integer, primary_key=True, index=True)
    server_id = Column(Integer, ForeignKey("hetzner_servers.id"), nullable=False, index=True)
    service_type = Column(String(20), nullable=False, index=True)  # backup | snapshot
    enabled = Column(Boolean, nullable=False, default=False)
    require_confirmation = Column(Boolean, nullable=False, default=True)
    schedule_mode = Column(String(20), nullable=False, default="manual")  # manual | interval
    interval_minutes = Column(Integer, nullable=True)
    retention_days = Column(Integer, nullable=True)
    retention_count = Column(Integer, nullable=True)
    last_run_at = Column(DateTime(timezone=True), nullable=True)
    next_run_at = Column(DateTime(timezone=True), nullable=True)
    last_status = Column(String(20), nullable=True)  # success | failed
    last_error = Column(Text, nullable=True)
    created_at = Column(DateTime(timezone=True), default=utcnow, nullable=False)
    updated_at = Column(DateTime(timezone=True), default=utcnow, onupdate=utcnow, nullable=False)

    server = relationship("HetznerServer", back_populates="policies")


class HetznerServiceLog(Base):
    __tablename__ = "hetzner_service_logs"

    id = Column(Integer, primary_key=True, index=True)
    server_id = Column(Integer, ForeignKey("hetzner_servers.id"), nullable=False, index=True)
    service_type = Column(String(20), nullable=False, index=True)  # backup | snapshot
    action = Column(String(40), nullable=False, default="run")
    status = Column(String(20), nullable=False, default="ok")
    message = Column(Text, nullable=False)
    created_by = Column(Integer, ForeignKey("users.id"), nullable=True, index=True)
    created_at = Column(DateTime(timezone=True), default=utcnow, nullable=False, index=True)

    server = relationship("HetznerServer", back_populates="logs")
