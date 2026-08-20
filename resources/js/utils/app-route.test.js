import { describe, expect, it } from "vitest";
import { appRoutePath, parseAppRoute } from "./app-route";

describe("application routes", () => {
    it("parses root and nested direct-navigation routes", () => {
        expect(parseAppRoute("/reports")).toEqual({
            view: "reports",
            subview: null,
        });
        expect(
            parseAppRoute("/restaurant/customers/42", "/restaurant"),
        ).toEqual({ view: "customers", subview: 42 });
        expect(
            parseAppRoute(
                "/clients/tools/ledger/more/audit-history",
                "/clients/tools/ledger",
            ),
        ).toEqual({ view: "more", subview: "audit_history" });
        expect(parseAppRoute("/more/settings")).toEqual({
            view: "more",
            subview: "settings",
        });
    });

    it("builds root and nested paths without hardcoded deployment folders", () => {
        expect(appRoutePath("new_sale")).toBe("/sales/new");
        expect(appRoutePath("customers", 9, "/restaurant")).toBe(
            "/restaurant/customers/9",
        );
        expect(appRoutePath("more", "staff", "/clients/tools/ledger")).toBe(
            "/clients/tools/ledger/more/staff",
        );
    });
});
