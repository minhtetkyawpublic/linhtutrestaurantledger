const permissionSet = (permissions = []) => new Set(permissions);

export const canViewNavigation = (permissions = []) =>
    permissionSet(permissions).size > 0;

export const hasPermission = (permissions = [], permission) =>
    permissionSet(permissions).has(permission);

export const hasAnyPermission = (permissions = [], candidates = []) =>
    candidates.some((permission) => permissionSet(permissions).has(permission));
