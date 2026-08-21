import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const api = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    setCsrf: vi.fn(),
}));

vi.mock("../lib/api", () => ({
    apiClient: api,
    setApiCsrfToken: api.setCsrf,
}));

import App from "./App";

const guestSession = { data: { user: null, permissions: [] } };
const adminUser = {
    id: 1,
    name: "Administrator",
    email: "admin@example.com",
    ui_locale: "en",
    is_disabled: false,
};
const page = (data) => ({
    data,
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: data.length,
});

function mockGet(sessionResponse = guestSession) {
    api.get.mockImplementation((path) => {
        if (path === "/auth/session") return Promise.resolve(sessionResponse);
        if (path === "/customers") return Promise.resolve({ data: page([]) });
        if (path === "/curry-items") return Promise.resolve({ data: [] });
        if (path === "/sales/create-options")
            return Promise.resolve({ data: { customers: [], curries: [] } });
        if (path === "/sales") return Promise.resolve({ data: [] });
        if (path === "/dashboard")
            return Promise.resolve({
                data: {
                    total_sales: 0,
                    sales_count: 0,
                    total_customer_debt: 0,
                    customers_owe_count: 0,
                    recent_activity: [],
                },
            });
        if (path === "/reports/sales-summary")
            return Promise.resolve({
                data: { total_sales: 0, sales_count: 0 },
            });
        if (path === "/reports/customer-balances")
            return Promise.resolve({
                data: { total_outstanding: 0, total_shop_owes: 0 },
            });
        if (path === "/reports/top-curries")
            return Promise.resolve({ data: {} });
        if (path === "/reports/filter-options")
            return Promise.resolve({
                data: { customers: [], curries: [] },
            });
        return Promise.resolve({ data: [] });
    });
}

beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    window.history.replaceState({}, "", "/");
    Object.defineProperty(navigator, "onLine", {
        configurable: true,
        value: true,
    });
    mockGet();
});

afterEach(() => cleanup());

describe("restaurant ledger app shell", () => {
    it("renders a designed login form for a guest session", async () => {
        const { container } = render(<App />);

        expect(
            await screen.findByRole("heading", {
                name: "Lin Htut Restaurant Ledger",
            }),
        ).toBeVisible();
        expect(screen.getByLabelText("Email")).toBeVisible();
        expect(screen.getByLabelText("Password")).toBeVisible();
        expect(screen.getByRole("button", { name: "Login" })).toBeEnabled();
        expect(
            container.querySelector(".brand-icon").getAttribute("src"),
        ).toContain("/linhtuticon.jpg");
    });

    it("switches the guest interface to Myanmar without English fallback labels", async () => {
        const user = userEvent.setup();
        render(<App />);

        await user.click(await screen.findByRole("button", { name: "မြန်မာ" }));

        expect(
            screen.getByRole("heading", {
                name: "လင်းထွဋ် စားသောက်ဆိုင် ငွေစာရင်း",
            }),
        ).toBeVisible();
        expect(screen.getByLabelText("အီးမေးလ်")).toBeVisible();
        expect(screen.getByLabelText("စကားဝှက်")).toBeVisible();
        expect(screen.getByRole("button", { name: "ဝင်မည်" })).toBeEnabled();
    });

    it("keeps server authentication errors localized in the Myanmar interface", async () => {
        const user = userEvent.setup();
        api.post.mockRejectedValue({
            response: {
                data: { message: "The provided credentials are incorrect." },
            },
        });
        render(<App />);

        await user.click(await screen.findByRole("button", { name: "မြန်မာ" }));
        await user.type(screen.getByLabelText("အီးမေးလ်"), "wrong@example.com");
        await user.type(screen.getByLabelText("စကားဝှက်"), "wrong-password");
        await user.click(screen.getByRole("button", { name: "ဝင်မည်" }));

        expect(await screen.findByText("အကောင့်ဝင်၍ မရပါ။")).toBeVisible();
        expect(
            screen.queryByText("The provided credentials are incorrect."),
        ).not.toBeInTheDocument();
    });

    it("logs in and renders the authenticated home screen", async () => {
        const user = userEvent.setup();
        api.post.mockResolvedValue({
            data: { user: adminUser, permissions: ["view_dashboard"] },
        });
        render(<App />);

        await user.type(
            await screen.findByLabelText("Email"),
            "admin@example.com",
        );
        await user.type(screen.getByLabelText("Password"), "ChangeMe123!");
        await user.click(screen.getByRole("button", { name: "Login" }));

        expect(
            await screen.findByText("Ready to record today’s business?"),
        ).toBeVisible();
        expect(api.post).toHaveBeenCalledWith(
            "/auth/login",
            expect.objectContaining({ email: "admin@example.com" }),
        );
        expect(api.setCsrf).toHaveBeenCalled();
    });

    it("hides dashboard figures when the user lacks dashboard permission", async () => {
        mockGet({
            data: {
                user: adminUser,
                permissions: ["create_sale"],
            },
        });
        render(<App />);

        await screen.findByRole("heading", { name: "Choose customer" });
        expect(screen.queryByText("Today’s sales")).not.toBeInTheDocument();
        expect(
            screen.queryByText("Total customer debt"),
        ).not.toBeInTheDocument();
        expect(api.get).not.toHaveBeenCalledWith("/dashboard");
    });

    it("opens the functional new-sale screen from bottom navigation", async () => {
        mockGet({
            data: {
                user: adminUser,
                permissions: [
                    "view_dashboard",
                    "create_sale",
                    "view_customers",
                ],
            },
        });
        render(<App />);

        await screen.findByText("Ready to record today’s business?");
        const saleButtons = screen.getAllByRole("button", { name: /New Sale/ });
        fireEvent.click(saleButtons.at(-1));

        expect(
            await screen.findByRole("heading", { name: "Choose customer" }),
        ).toBeVisible();
        expect(
            screen.getByLabelText("Search customer by name or phone"),
        ).toBeVisible();
        expect(window.location.pathname).toBe("/sales/new");
        await waitFor(() =>
            expect(api.get).toHaveBeenCalledWith("/sales/create-options"),
        );
    });

    it("restores an important screen from a direct browser route", async () => {
        window.history.replaceState({}, "", "/reports");
        mockGet({
            data: {
                user: adminUser,
                permissions: ["view_dashboard", "view_reports"],
            },
        });

        render(<App />);

        expect(
            await screen.findByRole("heading", { name: "Reports" }),
        ).toBeVisible();
        expect(window.location.pathname).toBe("/reports");
    });

    it("keeps report filters in a centered accessible modal", async () => {
        window.history.replaceState({}, "", "/reports");
        mockGet({
            data: {
                user: adminUser,
                permissions: ["view_reports"],
            },
        });
        render(<App />);

        fireEvent.click(await screen.findByRole("button", { name: "Filters" }));

        const dialog = screen.getByRole("dialog", { name: "Filters" });
        expect(dialog).toBeVisible();
        expect(dialog).toHaveClass("modal-dialog");
        expect(screen.getByLabelText("Date range")).toBeVisible();
        expect(
            screen.getByRole("button", { name: "Apply filters" }),
        ).toBeVisible();
    });

    it("redirects a direct route the user is not permitted to view", async () => {
        window.history.replaceState({}, "", "/reports");
        mockGet({
            data: {
                user: adminUser,
                permissions: ["create_sale"],
            },
        });

        render(<App />);

        expect(
            await screen.findByRole("heading", { name: "Choose customer" }),
        ).toBeVisible();
        expect(window.location.pathname).toBe("/sales/new");
        expect(
            api.get.mock.calls.some(([path]) => path.startsWith("/reports")),
        ).toBe(false);
    });

    it("blocks rapid duplicate sale submissions before the first request finishes", async () => {
        const customer = {
            id: 7,
            name: "Test Customer",
            phone_number: "091111111",
            current_balance_kyat: 0,
        };
        const curry = {
            id: 9,
            name: "Test Curry",
            current_price_kyat: 1500,
            is_available: true,
            is_archived: false,
        };
        mockGet({
            data: {
                user: adminUser,
                permissions: [
                    "view_dashboard",
                    "create_sale",
                    "view_customers",
                ],
            },
        });
        api.get.mockImplementation((path) => {
            if (path === "/auth/session")
                return Promise.resolve({
                    data: {
                        user: adminUser,
                        permissions: [
                            "view_dashboard",
                            "create_sale",
                            "view_customers",
                        ],
                    },
                });
            if (path === "/sales/create-options")
                return Promise.resolve({
                    data: { customers: [customer], curries: [curry] },
                });
            return Promise.resolve({ data: [] });
        });
        api.post.mockReturnValue(new Promise(() => {}));
        render(<App />);

        await screen.findByText("Ready to record today’s business?");
        fireEvent.click(
            screen.getAllByRole("button", { name: /New Sale/ }).at(-1),
        );
        await userEvent.selectOptions(
            await screen.findByLabelText("Customer"),
            "7",
        );
        expect(screen.getByLabelText("Search curries")).toBeVisible();
        fireEvent.click(screen.getByRole("button", { name: /Test Curry/ }));
        const saveButton = screen.getByRole("button", { name: "Save sale" });
        await waitFor(() => expect(saveButton).toBeEnabled());
        expect(screen.getByText("Resulting customer balance")).toBeVisible();
        expect(screen.getByText("Customer owes shop · 1,500 Ks")).toBeVisible();

        fireEvent.click(saveButton);
        fireEvent.click(saveButton);

        expect(api.post).toHaveBeenCalledTimes(1);
        expect(api.post).toHaveBeenCalledWith(
            "/sales",
            expect.objectContaining({ idempotency_key: expect.any(String) }),
        );
    });

    it("confirms a saved sale without rendering receipt actions", async () => {
        const customer = {
            id: 8,
            name: "Receipt Customer",
            phone_number: null,
            current_balance_kyat: 0,
        };
        const curry = {
            id: 10,
            name: "Receipt Curry",
            current_price_kyat: 900,
            is_available: true,
            is_archived: false,
        };
        const permissions = ["create_sale"];
        api.get.mockImplementation((path) => {
            if (path === "/auth/session")
                return Promise.resolve({
                    data: { user: adminUser, permissions },
                });
            if (path === "/sales/create-options")
                return Promise.resolve({
                    data: { customers: [customer], curries: [curry] },
                });
            return Promise.resolve({ data: [] });
        });
        api.post.mockResolvedValue({
            data: {
                id: 40,
                invoice_number: "SALE-40",
                sale_at: "2026-08-20T10:00:00.000Z",
                customer,
                subtotal_kyat: 900,
                discount_kyat: 0,
                total_kyat: 900,
                paid_kyat: 0,
                unpaid_kyat: 900,
                note: "Table 2",
                items: [
                    {
                        id: 41,
                        curry_name_snapshot: "Receipt Curry",
                        quantity: 1,
                        unit_price_snapshot_kyat: 900,
                        line_total_kyat: 900,
                    },
                ],
            },
        });
        render(<App />);

        await userEvent.selectOptions(
            await screen.findByLabelText("Customer"),
            "8",
        );
        fireEvent.click(screen.getByRole("button", { name: /Receipt Curry/ }));
        fireEvent.click(screen.getByRole("button", { name: "Save sale" }));

        expect(
            await screen.findByText("Sale saved successfully."),
        ).toBeVisible();
        expect(
            screen.queryByRole("heading", { name: "SALE-40" }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Share receipt" }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Save receipt PDF" }),
        ).not.toBeInTheDocument();
    });

    it("preserves a sale form through connection loss and saves after recovery", async () => {
        const customer = {
            id: 15,
            name: "Recovery Customer",
            current_balance_kyat: 0,
        };
        const curry = {
            id: 16,
            name: "Recovery Curry",
            current_price_kyat: 700,
            is_available: true,
            is_archived: false,
        };
        const permissions = ["create_sale"];
        api.get.mockImplementation((path) => {
            if (path === "/auth/session")
                return Promise.resolve({
                    data: { user: adminUser, permissions },
                });
            if (path === "/sales/create-options")
                return Promise.resolve({
                    data: { customers: [customer], curries: [curry] },
                });
            return Promise.resolve({ data: [] });
        });
        render(<App />);

        const customerSelect = await screen.findByLabelText("Customer");
        await userEvent.selectOptions(customerSelect, "15");
        fireEvent.click(screen.getByRole("button", { name: /Recovery Curry/ }));
        fireEvent.change(screen.getByLabelText("Paid amount"), {
            target: { value: "200" },
        });

        fireEvent(window, new Event("offline"));
        fireEvent.click(screen.getByRole("button", { name: "Save sale" }));
        expect(
            await screen.findAllByText(/Financial records cannot be saved/),
        ).not.toHaveLength(0);
        expect(customerSelect).toHaveValue("15");
        expect(screen.getByLabelText("Quantity Recovery Curry")).toHaveValue(1);
        expect(screen.getByLabelText("Paid amount")).toHaveValue(200);
        expect(api.post).not.toHaveBeenCalled();

        api.post.mockResolvedValue({
            data: {
                invoice_number: "RECOVERED-1",
                sale_at: "2026-08-20T10:00:00.000Z",
                customer,
                subtotal_kyat: 700,
                discount_kyat: 0,
                total_kyat: 700,
                paid_kyat: 200,
                unpaid_kyat: 500,
                items: [],
            },
        });
        fireEvent(window, new Event("online"));
        fireEvent.click(screen.getByRole("button", { name: "Save sale" }));

        expect(
            await screen.findByText("Sale saved successfully."),
        ).toBeVisible();
        expect(api.post).toHaveBeenCalledTimes(1);
    });

    it("applies the visible customer timeline date range to the ledger API", async () => {
        const customer = {
            id: 12,
            name: "Range Customer",
            phone_number: "092222222",
            current_balance_kyat: 0,
        };
        const permissions = [
            "view_dashboard",
            "view_customers",
            "view_customer_statements",
        ];
        api.get.mockImplementation((path) => {
            if (path === "/auth/session")
                return Promise.resolve({
                    data: { user: adminUser, permissions },
                });
            if (path === "/customers")
                return Promise.resolve({ data: page([customer]) });
            if (path === `/customers/${customer.id}`)
                return Promise.resolve({ data: customer });
            if (path === `/customers/${customer.id}/ledger`)
                return Promise.resolve({ data: page([]) });
            return Promise.resolve({ data: [] });
        });
        render(<App />);

        await screen.findByText("Ready to record today’s business?");
        fireEvent.click(screen.getByRole("button", { name: /Customers/ }));
        fireEvent.click(
            await screen.findByRole("button", { name: /Range Customer/ }),
        );
        await screen.findByRole("heading", { name: "Range Customer" });
        fireEvent.click(screen.getByRole("button", { name: "Filters" }));
        fireEvent.change(screen.getByLabelText("From"), {
            target: { value: "2026-08-01" },
        });
        fireEvent.change(screen.getByLabelText("To"), {
            target: { value: "2026-08-20" },
        });
        fireEvent.click(screen.getByRole("button", { name: "Apply filter" }));

        await waitFor(() =>
            expect(api.get).toHaveBeenCalledWith(
                `/customers/${customer.id}/ledger`,
                {
                    params: {
                        from: "2026-08-01",
                        to: "2026-08-20",
                        page: 1,
                        per_page: 15,
                    },
                },
            ),
        );
    });

    it("corrects a customer payment through an audited replacement request", async () => {
        const customer = {
            id: 13,
            name: "Correction Customer",
            phone_number: "093333333",
            current_balance_kyat: -300,
        };
        const entry = {
            id: 31,
            event_type: "customer_paid",
            amount_kyat: -300,
            balance_after_kyat: -300,
            reason: "Cash received",
            meta: { note: "Original note" },
            occurred_at: "2026-08-20T10:00:00.000Z",
            actor: { id: 1, name: "Administrator" },
            reversed_by: [],
        };
        const permissions = [
            "view_dashboard",
            "view_customers",
            "view_customer_statements",
            "correct_reverse_ledger",
        ];
        let corrected = false;
        api.get.mockImplementation((path) => {
            if (path === "/auth/session")
                return Promise.resolve({
                    data: { user: adminUser, permissions },
                });
            if (path === "/customers")
                return Promise.resolve({
                    data: page([
                        corrected
                            ? { ...customer, current_balance_kyat: -125 }
                            : customer,
                    ]),
                });
            if (path === `/customers/${customer.id}`)
                return Promise.resolve({
                    data: corrected
                        ? { ...customer, current_balance_kyat: -125 }
                        : customer,
                });
            if (path === `/customers/${customer.id}/ledger`)
                return Promise.resolve({ data: page([entry]) });
            return Promise.resolve({ data: [] });
        });
        api.post.mockImplementation(() => {
            corrected = true;
            return Promise.resolve({ data: {} });
        });
        render(<App />);

        await screen.findByText("Ready to record today’s business?");
        fireEvent.click(screen.getByRole("button", { name: /Customers/ }));
        fireEvent.click(
            await screen.findByRole("button", { name: /Correction Customer/ }),
        );
        fireEvent.click(await screen.findByRole("button", { name: "Edit" }));
        fireEvent.change(screen.getByLabelText("Corrected amount"), {
            target: { value: "125" },
        });
        fireEvent.change(screen.getByLabelText("Correction reason"), {
            target: { value: "Correct counting error" },
        });
        fireEvent.click(
            screen.getByRole("button", { name: "Save correction" }),
        );

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith(
                `/customers/${customer.id}/ledger/${entry.id}/correct`,
                {
                    amount_kyat: 125,
                    reason: "Correct counting error",
                    note: "Original note",
                },
            ),
        );
        expect(await screen.findAllByText("125 Ks")).not.toHaveLength(0);
    });

    it("lets authorized staff record a backdated customer payment", async () => {
        const customer = {
            id: 14,
            name: "Backdate Customer",
            phone_number: null,
            current_balance_kyat: 500,
        };
        const permissions = [
            "view_dashboard",
            "view_customers",
            "record_customer_payment",
            "backdate_sale",
        ];
        api.get.mockImplementation((path) => {
            if (path === "/auth/session")
                return Promise.resolve({
                    data: { user: adminUser, permissions },
                });
            if (path === "/customers")
                return Promise.resolve({ data: page([customer]) });
            if (path === `/customers/${customer.id}`)
                return Promise.resolve({ data: customer });
            if (path === `/customers/${customer.id}/ledger`)
                return Promise.resolve({ data: page([]) });
            return Promise.resolve({ data: [] });
        });
        api.post.mockResolvedValue({ data: {} });
        render(<App />);

        await screen.findByText("Ready to record today’s business?");
        fireEvent.click(screen.getByRole("button", { name: /Customers/ }));
        fireEvent.click(
            await screen.findByRole("button", { name: /Backdate Customer/ }),
        );
        await screen.findByRole("heading", { name: "Backdate Customer" });
        fireEvent.click(
            screen.getByRole("button", { name: "Customer Pays Shop" }),
        );
        fireEvent.change(screen.getByLabelText("Amount"), {
            target: { value: "200" },
        });
        fireEvent.change(screen.getByLabelText("Reason"), {
            target: { value: "Payment entered next day" },
        });
        fireEvent.change(screen.getByLabelText("Date and time"), {
            target: { value: "2026-08-19T10:30" },
        });
        fireEvent.click(screen.getByRole("button", { name: "Save" }));

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith(
                `/customers/${customer.id}/payments`,
                expect.objectContaining({
                    amount_kyat: 200,
                    occurred_at: "2026-08-19T03:30:00.000Z",
                    idempotency_key: expect.any(String),
                }),
            ),
        );
    });

    it("shows customer money validation errors inside the open modal", async () => {
        const customer = {
            id: 15,
            name: "Thailand Customer",
            phone_number: null,
            current_balance_kyat: 500,
        };
        const permissions = [
            "view_dashboard",
            "view_customers",
            "record_customer_payment",
            "backdate_sale",
        ];
        api.get.mockImplementation((path) => {
            if (path === "/auth/session")
                return Promise.resolve({
                    data: { user: adminUser, permissions },
                });
            if (path === "/customers")
                return Promise.resolve({ data: page([customer]) });
            if (path === `/customers/${customer.id}`)
                return Promise.resolve({ data: customer });
            if (path === `/customers/${customer.id}/ledger`)
                return Promise.resolve({ data: page([]) });
            return Promise.resolve({ data: [] });
        });
        api.post.mockRejectedValue({
            response: {
                data: {
                    errors: {
                        occurred_at: ["Date and time cannot be in the future."],
                    },
                },
            },
        });
        render(<App />);

        await screen.findByRole("heading", { name: /Ready to record/ });
        fireEvent.click(screen.getByRole("button", { name: /Customers/ }));
        fireEvent.click(
            await screen.findByRole("button", { name: /Thailand Customer/ }),
        );
        fireEvent.click(
            await screen.findByRole("button", {
                name: "Customer Pays Shop",
            }),
        );
        const dialog = screen.getByRole("dialog");
        fireEvent.change(within(dialog).getByLabelText("Amount"), {
            target: { value: "200" },
        });
        fireEvent.change(within(dialog).getByLabelText("Reason"), {
            target: { value: "Cash payment" },
        });
        fireEvent.click(within(dialog).getByRole("button", { name: "Save" }));

        expect(
            await within(dialog).findByText(
                "Date and time cannot be in the future.",
            ),
        ).toBeVisible();
    });

    it("shows a clear warning when connectivity is lost", async () => {
        mockGet({ data: { user: adminUser, permissions: ["view_dashboard"] } });
        render(<App />);
        await screen.findByText("Ready to record today’s business?");

        fireEvent(window, new Event("offline"));

        expect(
            await screen.findByText(/Financial records cannot be saved/),
        ).toBeVisible();
    });

    it("blocks sale reversals when connectivity is lost", async () => {
        const sale = {
            id: 21,
            invoice_number: "SALE-21",
            customer_id: 7,
            is_walk_in: false,
            sale_at: "2026-08-20T10:00:00.000Z",
            subtotal_kyat: 1500,
            discount_kyat: 0,
            total_kyat: 1500,
            paid_kyat: 500,
            unpaid_kyat: 1000,
            payment_status: "partial",
            note: null,
            is_reversed: false,
            customer: { id: 7, name: "Offline Customer" },
            items: [],
        };
        const permissions = [
            "view_dashboard",
            "view_sales_history",
            "delete_reverse_sale",
        ];
        api.get.mockImplementation((path) => {
            if (path === "/auth/session")
                return Promise.resolve({
                    data: { user: adminUser, permissions },
                });
            if (path === `/histories/sales/${sale.id}`)
                return Promise.resolve({ data: sale });
            return Promise.resolve({ data: [] });
        });
        Object.defineProperty(navigator, "onLine", {
            configurable: true,
            value: false,
        });
        window.history.replaceState({}, "", `/history/sale/${sale.id}`);

        render(<App />);

        fireEvent.click(await screen.findByRole("button", { name: "Reverse" }));
        expect(
            await screen.findAllByText(/Financial records cannot be saved/),
        ).not.toHaveLength(0);
        expect(api.post).not.toHaveBeenCalled();
    });
});
