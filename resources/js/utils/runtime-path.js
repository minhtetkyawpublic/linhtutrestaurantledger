const normalizePath = (path) => {
    if (path === "" || path === "/") {
        return "";
    }

    const normalized = `/${String(path).replace(/\/+$/, "").replace(/^\/+/, "")}`;
    return normalized;
};

export const deriveBasePathFromBundledUrl = (currentScriptUrl) => {
    const pathname = new URL(currentScriptUrl).pathname;
    const marker = "/build/";
    const markerIndex = pathname.lastIndexOf(marker);

    if (markerIndex > -1) {
        return normalizePath(pathname.substring(0, markerIndex));
    }

    if (typeof window !== "undefined" && window.__APP_BASE_PATH) {
        return normalizePath(window.__APP_BASE_PATH);
    }

    return "";
};

export const APP_BASE_PATH = deriveBasePathFromBundledUrl(import.meta.url);
export const APP_API_BASE =
    APP_BASE_PATH === "" ? "/api" : `${APP_BASE_PATH}/api`;

export function buildAppPath(path) {
    const normalizedPath = String(path).startsWith("/")
        ? String(path)
        : `/${path}`;
    return `${APP_BASE_PATH}${APP_BASE_PATH === "" ? normalizedPath : `${normalizedPath}`}`;
}

export function buildApiUrl(path) {
    const normalizedPath = String(path).startsWith("/")
        ? String(path)
        : `/${path}`;
    return `${APP_API_BASE}${normalizedPath}`;
}
