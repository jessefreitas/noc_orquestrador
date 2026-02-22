export const ACCESS_TOKEN_KEY = "omninoc_access_token";
export const REFRESH_TOKEN_KEY = "omninoc_refresh_token";
export const USER_EMAIL_KEY = "omninoc_user_email";

export function clearSession(): void {
  if (typeof window === "undefined") return;
  localStorage.removeItem(ACCESS_TOKEN_KEY);
  localStorage.removeItem(REFRESH_TOKEN_KEY);
  localStorage.removeItem(USER_EMAIL_KEY);
}
