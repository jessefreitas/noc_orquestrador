from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    app_name: str = "Orch API"
    app_env: str = "dev"
    api_prefix: str = "/v1"
    secret_key: str = "change-this-secret"
    access_token_expire_minutes: int = 30
    refresh_token_expire_minutes: int = 10080
    database_url: str = "sqlite:///./orch.db"
    redis_url: str = "redis://localhost:6379/0"
    admin_email: str = "admin@omniforge.com.br"
    admin_password: str = "Admin123!"
    cors_origins: str = "*"

    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    @property
    def cors_origins_list(self) -> list[str]:
        if self.cors_origins.strip() == "*":
            return ["*"]
        return [i.strip() for i in self.cors_origins.split(",") if i.strip()]


settings = Settings()

