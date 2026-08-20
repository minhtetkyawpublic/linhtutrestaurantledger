const morePanelPaths = {
    sales: "sales",
    curries: "curries",
    staff: "staff",
    audit_history: "audit-history",
    settings: "settings",
};

const normalizeBasePath = (basePath) => {
    const value = String(basePath || "").replace(/\/+$/, "");
    if (!value || value === "/") return "";
    return value.startsWith("/") ? value : `/${value}`;
};

const relativePath = (pathname, basePath) => {
    const base = normalizeBasePath(basePath);
    const path = String(pathname || "/").replace(/\/+$/, "") || "/";
    if (!base) return path;
    if (path === base) return "/";
    if (path.startsWith(`${base}/`)) return path.slice(base.length);
    return "/";
};

export function parseAppRoute(pathname, basePath = "") {
    const segments = relativePath(pathname, basePath)
        .split("/")
        .filter(Boolean);

    if (!segments.length) return { view: "home", subview: null };
    if (segments[0] === "sales" && segments[1] === "new")
        return { view: "new_sale", subview: null };
    if (segments[0] === "customers") {
        const customerId = /^\d+$/.test(segments[1] || "")
            ? Number(segments[1])
            : null;
        return { view: "customers", subview: customerId };
    }
    if (segments[0] === "reports") return { view: "reports", subview: null };
    if (segments[0] === "more") {
        const panel = Object.entries(morePanelPaths).find(
            ([, path]) => path === segments[1],
        )?.[0];
        return { view: "more", subview: panel || null };
    }

    return { view: "home", subview: null };
}

export function appRoutePath(view, subview = null, basePath = "") {
    let route = "/";
    if (view === "new_sale") route = "/sales/new";
    if (view === "customers")
        route = subview ? `/customers/${Number(subview)}` : "/customers";
    if (view === "reports") route = "/reports";
    if (view === "more")
        route =
            subview && morePanelPaths[subview]
                ? `/more/${morePanelPaths[subview]}`
                : "/more";

    return `${normalizeBasePath(basePath)}${route}`;
}
