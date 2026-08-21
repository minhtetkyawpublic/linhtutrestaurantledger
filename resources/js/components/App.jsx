import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { translations } from "../i18n/translations";
import { apiClient, setApiCsrfToken } from "../lib/api";
import { hasPermission } from "../lib/permissions";
import { appRoutePath, parseAppRoute } from "../utils/app-route";
import { APP_BASE_PATH } from "../utils/runtime-path";

const money = (value) =>
    `${new Intl.NumberFormat("en-US").format(Number(value || 0))} Ks`;
const nowForInput = () => {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    return now.toISOString().slice(0, 16);
};
const dateTimeForInput = (value) => {
    const date = new Date(value);
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
};
const makeKey = () =>
    globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`;
const errorMessage = (error, fallback) => {
    // Laravel's default validation/authentication messages are English. When
    // the active UI supplies a Myanmar fallback, do not leak those server
    // messages into an otherwise localized screen.
    if (/[\u1000-\u109f]/u.test(fallback)) return fallback;
    const errors = error?.response?.data?.errors;
    if (errors) return Object.values(errors).flat().join(" ");
    return error?.response?.data?.message ?? fallback;
};
const localizedRecordLabel = (t, prefix, record) => {
    const key = `${prefix}_${record.name}`;
    const translated = t(key);
    return translated === key
        ? record.display_name || record.label || record.name
        : translated;
};
const viewPermissions = {
    home: "view_dashboard",
    new_sale: "create_sale",
    customers: "view_customers",
    reports: "view_reports",
};

function useLanguage() {
    const [locale, setLocale] = useState(
        localStorage.getItem("ledger-locale") || "en",
    );
    const t = useCallback(
        (key) => translations[locale]?.[key] ?? translations.en[key] ?? key,
        [locale],
    );
    const changeLocale = useCallback((next) => {
        localStorage.setItem("ledger-locale", next);
        setLocale(next);
    }, []);
    return useMemo(
        () => ({ locale, changeLocale, t }),
        [locale, changeLocale, t],
    );
}

function Notice({ kind = "info", children }) {
    return children ? (
        <div className={`notice notice-${kind}`} role="status">
            {children}
        </div>
    ) : null;
}

function BrandIcon({ small = false }) {
    return (
        <img
            className={`brand-icon ${small ? "small" : ""}`}
            src={`${APP_BASE_PATH}/linhtuticon.jpg`}
            alt=""
        />
    );
}

function Modal({ title, onClose, children, wide = false }) {
    useEffect(() => {
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        const closeOnEscape = (event) => {
            if (event.key === "Escape") onClose();
        };
        document.addEventListener("keydown", closeOnEscape);
        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener("keydown", closeOnEscape);
        };
    }, [onClose]);

    return (
        <div
            className="modal-backdrop"
            role="presentation"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
        >
            <section
                className={`modal-dialog ${wide ? "wide" : ""}`}
                role="dialog"
                aria-modal="true"
                aria-labelledby="modal-title"
            >
                <header className="modal-header">
                    <h2 id="modal-title">{title}</h2>
                    <button
                        type="button"
                        className="modal-close"
                        onClick={onClose}
                        aria-label="Close"
                    >
                        ×
                    </button>
                </header>
                <div className="modal-body">{children}</div>
            </section>
        </div>
    );
}

function Loading({ t }) {
    return (
        <div className="loading-card">
            <span className="spinner" /> {t("loading")}
        </div>
    );
}

function Empty({ t }) {
    return <div className="empty-state">{t("nothing_here")}</div>;
}

function Balance({ value, t, large = false }) {
    const amount = Number(value || 0);
    const key =
        amount > 0
            ? "customer_owes_shop"
            : amount < 0
              ? "shop_owes_customer"
              : "settled";
    const tone = amount > 0 ? "debt" : amount < 0 ? "credit" : "settled";
    return (
        <div
            className={`balance balance-${tone} ${large ? "balance-large" : ""}`}
        >
            <span>{t(key)}</span>
            <strong>{money(Math.abs(amount))}</strong>
        </div>
    );
}

function download(data, filename) {
    const url = URL.createObjectURL(
        new Blob([data], { type: "application/pdf" }),
    );
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

async function shareOrDownload(data, filename, title) {
    const blob = new Blob([data], { type: "application/pdf" });
    const file = new File([blob], filename, { type: "application/pdf" });
    const payload = { files: [file], title };
    if (
        navigator.share &&
        (!navigator.canShare || navigator.canShare(payload))
    ) {
        try {
            await navigator.share(payload);
        } catch (error) {
            if (error?.name === "AbortError") return;
            throw error;
        }
        return;
    }
    download(blob, filename);
}

function LoginScreen({ t, locale, onLocale, onLogin, loading, error }) {
    const [form, setForm] = useState({
        email: "",
        password: "",
        remember: false,
    });
    return (
        <main className="auth-page">
            <section className="auth-card">
                <BrandIcon />
                <p className="eyebrow">{t("restaurant_ledger")}</p>
                <h1>{t("app_name")}</h1>
                <p className="muted">{t("login_welcome")}</p>
                <form
                    className="stack"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onLogin(form);
                    }}
                >
                    <label>
                        {t("email")}
                        <input
                            type="email"
                            autoComplete="username"
                            value={form.email}
                            onChange={(event) =>
                                setForm({ ...form, email: event.target.value })
                            }
                            required
                        />
                    </label>
                    <label>
                        {t("password")}
                        <input
                            type="password"
                            autoComplete="current-password"
                            value={form.password}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    password: event.target.value,
                                })
                            }
                            required
                        />
                    </label>
                    <label className="check-row">
                        <input
                            type="checkbox"
                            checked={form.remember}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    remember: event.target.checked,
                                })
                            }
                        />
                        {t("remember")}
                    </label>
                    <Notice kind="error">{error}</Notice>
                    <button className="primary large" disabled={loading}>
                        {loading ? t("loading") : t("login")}
                    </button>
                </form>
                <button
                    className="text-button language-button"
                    type="button"
                    onClick={() => onLocale(locale === "en" ? "my" : "en")}
                >
                    {locale === "en" ? t("language_my") : t("language_en")}
                </button>
            </section>
        </main>
    );
}

function HomeScreen({ t, permissions, goTo }) {
    const [customerQuery, setCustomerQuery] = useState("");
    const [data, setData] = useState({
        dashboard: null,
        customers: [],
    });
    const [loading, setLoading] = useState(true);
    useEffect(() => {
        let active = true;
        const requests = [];
        const keys = [];
        if (hasPermission(permissions, "view_dashboard")) {
            requests.push(apiClient.get("/dashboard"));
            keys.push("dashboard");
        }
        if (hasPermission(permissions, "view_customers")) {
            requests.push(apiClient.get("/customers"));
            keys.push("customers");
        }
        Promise.allSettled(requests).then((results) => {
            if (!active) return;
            setData((previous) => {
                const next = { ...previous };
                results.forEach((result, index) => {
                    if (result.status === "fulfilled")
                        next[keys[index]] = result.value.data;
                });
                return next;
            });
            setLoading(false);
        });
        return () => {
            active = false;
        };
    }, [permissions]);
    const quickCustomers = useMemo(() => {
        const query = customerQuery.trim().toLocaleLowerCase();
        return data.customers
            .filter((customer) =>
                !query
                    ? true
                    : `${customer.name} ${customer.phone_number || ""}`
                          .toLocaleLowerCase()
                          .includes(query),
            )
            .slice(0, 4);
    }, [customerQuery, data.customers]);
    if (loading) return <Loading t={t} />;
    return (
        <div className="screen stack-lg">
            <div className="hero-card">
                <div>
                    <p className="eyebrow">{t("today")}</p>
                    <h2>{t("ready_to_record")}</h2>
                </div>
                {hasPermission(permissions, "create_sale") && (
                    <button
                        className="primary large"
                        onClick={() => goTo("new_sale")}
                    >
                        ＋ {t("new_sale")}
                    </button>
                )}
            </div>
            {hasPermission(permissions, "view_dashboard") && (
                <>
                    <div className="stat-grid">
                        <article className="stat-card orange">
                            <span>{t("todays_sales")}</span>
                            <strong>
                                {money(data.dashboard?.total_sales)}
                            </strong>
                            <small>
                                {data.dashboard?.sales_count || 0} {t("sales")}
                            </small>
                        </article>
                        <article className="stat-card red">
                            <span>{t("total_customer_debt")}</span>
                            <strong>
                                {money(data.dashboard?.total_customer_debt)}
                            </strong>
                            <small>
                                {data.dashboard?.customers_owe_count || 0}{" "}
                                {t("customers")}
                            </small>
                        </article>
                    </div>
                    <section className="panel">
                        <div className="section-heading">
                            <div>
                                <p className="eyebrow">{t("latest")}</p>
                                <h3>{t("recent_customer_activity")}</h3>
                            </div>
                            {hasPermission(permissions, "view_customers") && (
                                <button
                                    className="text-button"
                                    onClick={() => goTo("customers")}
                                >
                                    {t("view_all")}
                                </button>
                            )}
                        </div>
                        {data.dashboard?.recent_activity?.length ? (
                            <div className="list">
                                {data.dashboard.recent_activity.map((entry) => (
                                    <div className="list-row" key={entry.id}>
                                        <div>
                                            <strong>
                                                {entry.customer?.name}
                                            </strong>
                                            <small>
                                                {t(`event_${entry.event_type}`)}
                                            </small>
                                        </div>
                                        <div className="align-right">
                                            <strong>
                                                {money(
                                                    Math.abs(entry.amount_kyat),
                                                )}
                                            </strong>
                                            <small>
                                                {new Date(
                                                    entry.occurred_at,
                                                ).toLocaleString()}
                                            </small>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <Empty t={t} />
                        )}
                    </section>
                </>
            )}
            {hasPermission(permissions, "view_customers") && (
                <section className="panel">
                    <div className="section-heading">
                        <h3>{t("quick_customer_search")}</h3>
                        <button
                            className="text-button"
                            onClick={() => goTo("customers")}
                        >
                            {t("open_customers")}
                        </button>
                    </div>
                    <label>
                        {t("search_name_phone")}
                        <input
                            type="search"
                            value={customerQuery}
                            onChange={(event) =>
                                setCustomerQuery(event.target.value)
                            }
                        />
                    </label>
                    {quickCustomers.map((customer) => (
                        <button
                            className="customer-row"
                            key={customer.id}
                            onClick={() => goTo("customers", customer.id)}
                        >
                            <span>
                                <strong>{customer.name}</strong>
                                <small>
                                    {customer.phone_number || t("no_phone")}
                                </small>
                            </span>
                            <Balance
                                value={customer.current_balance_kyat}
                                t={t}
                            />
                        </button>
                    ))}
                </section>
            )}
        </div>
    );
}

function NewSaleScreen({
    t,
    permissions,
    presetCustomerId,
    clearPreset,
    online,
}) {
    const [customers, setCustomers] = useState([]);
    const [curries, setCurries] = useState([]);
    const [customerQuery, setCustomerQuery] = useState("");
    const [curryQuery, setCurryQuery] = useState("");
    const [curryCategory, setCurryCategory] = useState("");
    const [items, setItems] = useState([]);
    const [form, setForm] = useState({
        customer_id: presetCustomerId || "",
        is_walk_in: false,
        discount_kyat: 0,
        paid_kyat: 0,
        sale_at: nowForInput(),
        note: "",
    });
    const [status, setStatus] = useState({
        loading: true,
        saving: false,
        error: "",
        success: "",
        sale: null,
    });
    const savingRef = useRef(false);
    const submissionRef = useRef(null);
    const load = useCallback(async () => {
        try {
            const response = await apiClient.get("/sales/create-options");
            setCustomers(response.data.customers);
            setCurries(response.data.curries);
        } catch (error) {
            setStatus((state) => ({
                ...state,
                error: errorMessage(error, t("load_failed")),
            }));
        } finally {
            setStatus((state) => ({ ...state, loading: false }));
        }
    }, [t]);
    useEffect(() => {
        load();
    }, [load]);
    useEffect(() => {
        if (presetCustomerId) {
            setForm((current) => ({
                ...current,
                customer_id: presetCustomerId,
                is_walk_in: false,
            }));
            clearPreset();
        }
    }, [presetCustomerId, clearPreset]);
    const subtotal = items.reduce(
        (sum, line) => sum + line.current_price_kyat * line.quantity,
        0,
    );
    const total = Math.max(0, subtotal - Number(form.discount_kyat || 0));
    const unpaid = total - Number(form.paid_kyat || 0);
    const selectedCustomer = customers.find(
        (customer) => customer.id === Number(form.customer_id),
    );
    const filteredCustomers = useMemo(() => {
        const query = customerQuery.trim().toLocaleLowerCase();
        if (!query) return customers;
        return customers.filter((customer) =>
            `${customer.name} ${customer.phone_number || ""}`
                .toLocaleLowerCase()
                .includes(query),
        );
    }, [customerQuery, customers]);
    const curryCategories = useMemo(
        () =>
            Array.from(
                new Map(
                    curries
                        .filter((curry) => curry.category)
                        .map((curry) => [curry.category.id, curry.category]),
                ).values(),
            ),
        [curries],
    );
    const filteredCurries = useMemo(() => {
        const query = curryQuery.trim().toLocaleLowerCase();
        return curries.filter(
            (curry) =>
                (!curryCategory ||
                    curry.category?.id === Number(curryCategory)) &&
                (!query || curry.name.toLocaleLowerCase().includes(query)),
        );
    }, [curries, curryCategory, curryQuery]);
    const resultingBalance =
        Number(selectedCustomer?.current_balance_kyat || 0) + unpaid;
    const resultingBalanceKey =
        resultingBalance > 0
            ? "customer_owes_shop"
            : resultingBalance < 0
              ? "shop_owes_customer"
              : "settled";
    const addItem = (curry) =>
        setItems((current) =>
            current.some((line) => line.id === curry.id)
                ? current.map((line) =>
                      line.id === curry.id
                          ? { ...line, quantity: line.quantity + 1 }
                          : line,
                  )
                : [...current, { ...curry, quantity: 1 }],
        );
    const quantity = (id, delta) =>
        setItems((current) =>
            current
                .map((line) =>
                    line.id === id
                        ? {
                              ...line,
                              quantity: Math.max(0, line.quantity + delta),
                          }
                        : line,
                )
                .filter((line) => line.quantity > 0),
        );
    const setQuantity = (id, value) => {
        const nextQuantity = Math.max(1, Number(value || 1));
        setItems((current) =>
            current.map((line) =>
                line.id === id ? { ...line, quantity: nextQuantity } : line,
            ),
        );
    };
    const save = async () => {
        if (savingRef.current) return;
        if (!online) {
            setStatus((state) => ({ ...state, error: t("offline_warning") }));
            return;
        }
        const payload = {
            ...form,
            customer_id: form.is_walk_in ? null : Number(form.customer_id),
            discount_kyat: Number(form.discount_kyat || 0),
            paid_kyat: Number(form.paid_kyat || 0),
            sale_at: new Date(form.sale_at).toISOString(),
            items: items.map((line) => ({
                curry_item_id: line.id,
                quantity: line.quantity,
            })),
        };
        const fingerprint = JSON.stringify(payload);
        if (submissionRef.current?.fingerprint !== fingerprint)
            submissionRef.current = { fingerprint, key: makeKey() };
        savingRef.current = true;
        setStatus((state) => ({
            ...state,
            saving: true,
            error: "",
            success: "",
            sale: null,
        }));
        try {
            const response = await apiClient.post("/sales", {
                ...payload,
                idempotency_key: submissionRef.current.key,
            });
            submissionRef.current = null;
            setStatus((state) => ({
                ...state,
                saving: false,
                success: t("sale_saved"),
                sale: response.data,
            }));
            setItems([]);
            setForm((current) => ({
                ...current,
                discount_kyat: 0,
                paid_kyat: 0,
                note: "",
                sale_at: nowForInput(),
            }));
        } catch (error) {
            setStatus((state) => ({
                ...state,
                saving: false,
                error: errorMessage(error, t("save_failed")),
            }));
        } finally {
            savingRef.current = false;
        }
    };
    const receipt = async (action) => {
        try {
            const response = await apiClient.get(
                `/sales/${status.sale.id}/receipt`,
                { responseType: "blob" },
            );
            const filename = `receipt-${status.sale.invoice_number}.pdf`;
            if (action === "save") download(response.data, filename);
            else await shareOrDownload(response.data, filename, t("receipt"));
        } catch (error) {
            setStatus((state) => ({
                ...state,
                error: errorMessage(error, t("download_failed")),
            }));
        }
    };
    if (status.loading) return <Loading t={t} />;
    return (
        <div className="screen sale-layout">
            <section className="panel stack">
                <div className="section-heading">
                    <div>
                        <p className="eyebrow">{t("step_one")}</p>
                        <h2>{t("choose_customer")}</h2>
                    </div>
                </div>
                <label className="check-card">
                    <input
                        type="checkbox"
                        checked={form.is_walk_in}
                        onChange={(event) =>
                            setForm({
                                ...form,
                                is_walk_in: event.target.checked,
                                customer_id: "",
                                paid_kyat: event.target.checked
                                    ? total
                                    : form.paid_kyat,
                            })
                        }
                    />
                    <span>
                        <strong>{t("walkin")}</strong>
                        <small>{t("walkin_fully_paid")}</small>
                    </span>
                </label>
                {!form.is_walk_in && (
                    <>
                        <label>
                            {t("search_customer")}
                            <input
                                type="search"
                                value={customerQuery}
                                onChange={(event) =>
                                    setCustomerQuery(event.target.value)
                                }
                            />
                        </label>
                        <label>
                            {t("customer")}
                            <select
                                value={form.customer_id}
                                onChange={(event) => {
                                    setForm({
                                        ...form,
                                        customer_id: event.target.value,
                                    });
                                    setCustomerQuery("");
                                }}
                            >
                                <option value="">{t("select_customer")}</option>
                                {filteredCustomers.map((customer) => (
                                    <option
                                        key={customer.id}
                                        value={customer.id}
                                    >
                                        {customer.name}
                                        {customer.phone_number
                                            ? ` — ${customer.phone_number}`
                                            : ""}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </>
                )}
            </section>
            <section className="panel stack">
                <div className="section-heading">
                    <div>
                        <p className="eyebrow">{t("step_two")}</p>
                        <h2>{t("add_curry_items")}</h2>
                    </div>
                    <span className="pill">{curries.length}</span>
                </div>
                {curries.length ? (
                    <>
                        <div className="filter-grid compact-filter-grid">
                            <label>
                                {t("search_curries")}
                                <input
                                    type="search"
                                    value={curryQuery}
                                    onChange={(event) =>
                                        setCurryQuery(event.target.value)
                                    }
                                />
                            </label>
                            <label>
                                {t("category")}
                                <select
                                    value={curryCategory}
                                    onChange={(event) =>
                                        setCurryCategory(event.target.value)
                                    }
                                >
                                    <option value="">
                                        {t("all_categories")}
                                    </option>
                                    {curryCategories.map((category) => (
                                        <option
                                            key={category.id}
                                            value={category.id}
                                        >
                                            {category.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        <div className="curry-grid">
                            {filteredCurries.map((curry) => (
                                <button
                                    className="curry-button"
                                    key={curry.id}
                                    onClick={() => addItem(curry)}
                                >
                                    <strong>{curry.name}</strong>
                                    <span>
                                        {money(curry.current_price_kyat)}
                                    </span>
                                    <b>＋</b>
                                </button>
                            ))}
                        </div>
                        {!filteredCurries.length && <Empty t={t} />}
                    </>
                ) : (
                    <Notice>{t("add_curries_first")}</Notice>
                )}
            </section>
            <section className="panel order-panel stack">
                <div className="section-heading">
                    <div>
                        <p className="eyebrow">{t("step_three")}</p>
                        <h2>{t("order")}</h2>
                    </div>
                    <span className="pill">
                        {items.reduce((sum, line) => sum + line.quantity, 0)}
                    </span>
                </div>
                {items.length ? (
                    <div className="list">
                        {items.map((line) => (
                            <div className="order-row" key={line.id}>
                                <div>
                                    <strong>{line.name}</strong>
                                    <small>
                                        {money(line.current_price_kyat)} ×{" "}
                                        {line.quantity}
                                    </small>
                                </div>
                                <div className="quantity">
                                    <button
                                        onClick={() => quantity(line.id, -1)}
                                    >
                                        −
                                    </button>
                                    <input
                                        type="number"
                                        min="1"
                                        inputMode="numeric"
                                        aria-label={`${t("quantity")} ${line.name}`}
                                        value={line.quantity}
                                        onChange={(event) =>
                                            setQuantity(
                                                line.id,
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <button
                                        onClick={() => quantity(line.id, 1)}
                                    >
                                        ＋
                                    </button>
                                </div>
                                <strong>
                                    {money(
                                        line.current_price_kyat * line.quantity,
                                    )}
                                </strong>
                            </div>
                        ))}
                    </div>
                ) : (
                    <Empty t={t} />
                )}
                <div className="two-column">
                    <label>
                        {t("discount")}
                        <input
                            type="number"
                            inputMode="numeric"
                            min="0"
                            value={form.discount_kyat}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    discount_kyat: event.target.value,
                                })
                            }
                        />
                    </label>
                    <label>
                        {t("paid_amount")}
                        <input
                            type="number"
                            inputMode="numeric"
                            min="0"
                            value={form.paid_kyat}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    paid_kyat: event.target.value,
                                })
                            }
                        />
                    </label>
                </div>
                <details className="advanced-fields">
                    <summary>{t("advanced_fields")}</summary>
                    <div className="stack">
                        {hasPermission(permissions, "backdate_sale") && (
                            <label>
                                {t("sale_date_time")}
                                <input
                                    type="datetime-local"
                                    value={form.sale_at}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            sale_at: event.target.value,
                                        })
                                    }
                                />
                            </label>
                        )}
                        <label>
                            {t("note")}
                            <textarea
                                value={form.note}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        note: event.target.value,
                                    })
                                }
                            />
                        </label>
                    </div>
                </details>
                <div className="totals">
                    <span>
                        {t("subtotal")}
                        <b>{money(subtotal)}</b>
                    </span>
                    <span>
                        {t("discount")}
                        <b>− {money(form.discount_kyat)}</b>
                    </span>
                    <span className="grand-total">
                        {t("total")}
                        <b>{money(total)}</b>
                    </span>
                    <span>
                        {unpaid >= 0 ? t("debt_added") : t("customer_credit")}
                        <b>{money(Math.abs(unpaid))}</b>
                    </span>
                    {!form.is_walk_in && selectedCustomer && (
                        <span>
                            {t("resulting_balance")}
                            <b>
                                {t(resultingBalanceKey)} ·{" "}
                                {money(Math.abs(resultingBalance))}
                            </b>
                        </span>
                    )}
                </div>
                <Notice kind="error">{status.error}</Notice>
                <Notice kind="success">{status.success}</Notice>
                {status.sale && (
                    <section className="inset-form stack receipt-preview">
                        <div className="section-heading">
                            <div>
                                <p className="eyebrow">{t("receipt")}</p>
                                <h3>{status.sale.invoice_number}</h3>
                            </div>
                            <small>
                                {new Date(status.sale.sale_at).toLocaleString()}
                            </small>
                        </div>
                        <small>
                            {t("customer")}:{" "}
                            {status.sale.customer?.name || t("walkin")}
                        </small>
                        <div className="list">
                            {status.sale.items?.map((item) => (
                                <div className="list-row" key={item.id}>
                                    <span>
                                        <strong>
                                            {item.curry_name_snapshot}
                                        </strong>
                                        <small>
                                            {t("quantity")}: {item.quantity}
                                            {" · "}
                                            {t("unit_price")}:{" "}
                                            {money(
                                                item.unit_price_snapshot_kyat,
                                            )}
                                        </small>
                                    </span>
                                    <strong>
                                        {money(item.line_total_kyat)}
                                    </strong>
                                </div>
                            ))}
                        </div>
                        <div className="totals">
                            <span>
                                {t("subtotal")}
                                <b>{money(status.sale.subtotal_kyat)}</b>
                            </span>
                            <span>
                                {t("discount")}
                                <b>{money(status.sale.discount_kyat)}</b>
                            </span>
                            <span className="grand-total">
                                {t("total")}
                                <b>{money(status.sale.total_kyat)}</b>
                            </span>
                            <span>
                                {t("paid_amount")}
                                <b>{money(status.sale.paid_kyat)}</b>
                            </span>
                            <span>
                                {status.sale.unpaid_kyat < 0
                                    ? t("customer_credit")
                                    : t("unpaid")}
                                <b>
                                    {money(Math.abs(status.sale.unpaid_kyat))}
                                </b>
                            </span>
                        </div>
                        {status.sale.note && (
                            <small>
                                {t("note")}: {status.sale.note}
                            </small>
                        )}
                        <div className="button-row">
                            <button
                                className="secondary"
                                onClick={() => receipt("share")}
                            >
                                {t("share_receipt")}
                            </button>
                            <button
                                className="secondary"
                                onClick={() => receipt("save")}
                            >
                                {t("save_receipt")}
                            </button>
                        </div>
                    </section>
                )}
                <div className="sale-save-dock">
                    <span>
                        <small>{t("total")}</small>
                        <strong>{money(total)}</strong>
                    </span>
                    <button
                        className="primary large"
                        disabled={
                            status.saving ||
                            !items.length ||
                            (!form.is_walk_in && !form.customer_id)
                        }
                        onClick={save}
                    >
                        {status.saving ? t("saving") : t("save_sale")}
                    </button>
                </div>
            </section>
        </div>
    );
}

function CustomersScreen({
    t,
    permissions,
    initialCustomerId,
    onNewSale,
    online,
}) {
    const [customers, setCustomers] = useState([]);
    const [query, setQuery] = useState("");
    const [selected, setSelected] = useState(null);
    const [ledger, setLedger] = useState([]);
    const [ledgerRange, setLedgerRange] = useState({ from: "", to: "" });
    const [appliedLedgerRange, setAppliedLedgerRange] = useState({
        from: "",
        to: "",
    });
    const [showCreate, setShowCreate] = useState(false);
    const [showLedgerFilters, setShowLedgerFilters] = useState(false);
    const emptyCustomerForm = {
        id: null,
        name: "",
        phone_number: "",
        address_or_note: "",
        opening_balance_kyat: 0,
        opening_balance_reason: "",
        is_archived: false,
    };
    const [customerForm, setCustomerForm] = useState({ ...emptyCustomerForm });
    const [moneyForm, setMoneyForm] = useState({
        type: "",
        amount_kyat: "",
        reason: "",
        note: "",
        occurred_at: "",
    });
    const [moneySaving, setMoneySaving] = useState(false);
    const [correctionForm, setCorrectionForm] = useState(null);
    const [correctionSaving, setCorrectionSaving] = useState(false);
    const correctionSavingRef = useRef(false);
    const moneySavingRef = useRef(false);
    const moneySubmissionRef = useRef(null);
    const [message, setMessage] = useState({ error: "", success: "" });
    const loadCustomers = useCallback(
        async (q = "") => {
            try {
                const response = await apiClient.get("/customers", {
                    params: q ? { q } : {},
                });
                setCustomers(response.data);
                setSelected((current) => {
                    const targetId = current?.id ?? Number(initialCustomerId);
                    if (!targetId) return current;

                    return (
                        response.data.find((item) => item.id === targetId) ??
                        current
                    );
                });
            } catch (error) {
                setMessage({
                    error: errorMessage(error, t("load_failed")),
                    success: "",
                });
            }
        },
        [initialCustomerId, t],
    );
    const loadLedger = useCallback(
        async (customer) => {
            if (
                !customer ||
                !hasPermission(permissions, "view_customer_statements")
            )
                return;
            const params =
                appliedLedgerRange.from && appliedLedgerRange.to
                    ? appliedLedgerRange
                    : {};
            try {
                setLedger(
                    (
                        await apiClient.get(
                            `/customers/${customer.id}/ledger`,
                            { params },
                        )
                    ).data,
                );
            } catch (error) {
                setMessage({
                    error: errorMessage(error, t("load_failed")),
                    success: "",
                });
            }
        },
        [appliedLedgerRange, permissions, t],
    );
    useEffect(() => {
        loadCustomers();
    }, [loadCustomers]);
    useEffect(() => {
        loadLedger(selected);
    }, [selected, loadLedger]);
    useEffect(() => {
        setCorrectionForm(null);
    }, [selected?.id]);
    const saveCustomer = async (event) => {
        event.preventDefault();
        if (!online) {
            setMessage({ error: t("offline_warning"), success: "" });
            return;
        }
        setMessage({ error: "", success: "" });
        try {
            const payload = {
                ...customerForm,
                opening_balance_kyat: Number(
                    customerForm.opening_balance_kyat || 0,
                ),
            };
            const response = customerForm.id
                ? await apiClient.put(`/customers/${customerForm.id}`, payload)
                : await apiClient.post("/customers", payload);
            setCustomerForm({ ...emptyCustomerForm });
            setShowCreate(false);
            setSelected(response.data);
            await loadCustomers(query);
            setMessage({ error: "", success: t("customer_saved") });
        } catch (error) {
            setMessage({
                error: errorMessage(error, t("save_failed")),
                success: "",
            });
        }
    };
    const editCustomer = () => {
        setCustomerForm({
            id: selected.id,
            name: selected.name,
            phone_number: selected.phone_number || "",
            address_or_note: selected.address_or_note || "",
            opening_balance_kyat: selected.opening_balance_kyat || 0,
            opening_balance_reason: "",
            is_archived: selected.is_archived,
        });
        setShowCreate(true);
    };
    const archiveCustomer = async () => {
        if (!window.confirm(t("confirm_archive_customer"))) return;
        try {
            await apiClient.put(`/customers/${selected.id}`, {
                name: selected.name,
                phone_number: selected.phone_number,
                address_or_note: selected.address_or_note,
                opening_balance_kyat: selected.opening_balance_kyat || 0,
                opening_balance_reason: selected.opening_balance_reason,
                is_archived: true,
            });
            setSelected(null);
            await loadCustomers(query);
            setMessage({ error: "", success: t("customer_archived") });
        } catch (error) {
            setMessage({
                error: errorMessage(error, t("save_failed")),
                success: "",
            });
        }
    };
    const recordMoney = async (event) => {
        event.preventDefault();
        if (moneySavingRef.current) return;
        if (!online) {
            setMessage({ error: t("offline_warning"), success: "" });
            return;
        }
        const endpoint =
            moneyForm.type === "payment" ? "payments" : "money-lent";
        const payload = {
            amount_kyat: Number(moneyForm.amount_kyat),
            reason: moneyForm.reason,
            note: moneyForm.note,
            ...(moneyForm.occurred_at
                ? { occurred_at: moneyForm.occurred_at }
                : {}),
        };
        const fingerprint = JSON.stringify({
            customer_id: selected.id,
            endpoint,
            ...payload,
        });
        if (moneySubmissionRef.current?.fingerprint !== fingerprint)
            moneySubmissionRef.current = { fingerprint, key: makeKey() };
        moneySavingRef.current = true;
        setMoneySaving(true);
        try {
            await apiClient.post(`/customers/${selected.id}/${endpoint}`, {
                ...payload,
                idempotency_key: moneySubmissionRef.current.key,
            });
            moneySubmissionRef.current = null;
            setMoneyForm({
                type: "",
                amount_kyat: "",
                reason: "",
                note: "",
                occurred_at: "",
            });
            await Promise.all([loadLedger(selected), loadCustomers(query)]);
            setMessage({ error: "", success: t("entry_saved") });
        } catch (error) {
            setMessage({
                error: errorMessage(error, t("save_failed")),
                success: "",
            });
        } finally {
            moneySavingRef.current = false;
            setMoneySaving(false);
        }
    };
    const reverseEntry = async (entry) => {
        if (!online) {
            setMessage({ error: t("offline_warning"), success: "" });
            return;
        }
        const reason = window.prompt(t("enter_reversal_reason"));
        if (!reason) return;
        try {
            await apiClient.post(
                `/customers/${selected.id}/ledger/${entry.id}/reverse`,
                { reason },
            );
            await Promise.all([loadLedger(selected), loadCustomers(query)]);
            setMessage({ error: "", success: t("entry_reversed") });
        } catch (error) {
            setMessage({
                error: errorMessage(error, t("save_failed")),
                success: "",
            });
        }
    };
    const beginEntryCorrection = (entry) => {
        setCorrectionForm({
            entry_id: entry.id,
            event_type: entry.event_type,
            amount_kyat: Math.abs(entry.amount_kyat),
            reason: "",
            note: entry.meta?.note || "",
            occurred_at: hasPermission(permissions, "backdate_sale")
                ? dateTimeForInput(entry.occurred_at)
                : "",
        });
    };
    const correctEntry = async (event) => {
        event.preventDefault();
        if (!online) {
            setMessage({ error: t("offline_warning"), success: "" });
            return;
        }
        if (correctionSavingRef.current) return;
        correctionSavingRef.current = true;
        setCorrectionSaving(true);
        setMessage({ error: "", success: "" });
        try {
            await apiClient.post(
                `/customers/${selected.id}/ledger/${correctionForm.entry_id}/correct`,
                {
                    amount_kyat: Number(correctionForm.amount_kyat),
                    reason: correctionForm.reason,
                    note: correctionForm.note,
                    ...(correctionForm.occurred_at
                        ? { occurred_at: correctionForm.occurred_at }
                        : {}),
                },
            );
            setCorrectionForm(null);
            await Promise.all([loadLedger(selected), loadCustomers(query)]);
            setMessage({ error: "", success: t("entry_corrected") });
        } catch (error) {
            setMessage({
                error: errorMessage(error, t("save_failed")),
                success: "",
            });
        } finally {
            correctionSavingRef.current = false;
            setCorrectionSaving(false);
        }
    };
    const applyLedgerFilter = (event) => {
        event.preventDefault();
        if (
            (ledgerRange.from && !ledgerRange.to) ||
            (!ledgerRange.from && ledgerRange.to)
        ) {
            setMessage({ error: t("both_dates_required"), success: "" });
            return;
        }
        setMessage({ error: "", success: "" });
        setAppliedLedgerRange({ ...ledgerRange });
        setShowLedgerFilters(false);
    };
    const statement = async (action) => {
        try {
            const params =
                appliedLedgerRange.from && appliedLedgerRange.to
                    ? appliedLedgerRange
                    : {};
            const response = await apiClient.get(
                `/customers/${selected.id}/statement`,
                { responseType: "blob", params },
            );
            const filename = `statement-${selected.id}.pdf`;
            if (action === "save") download(response.data, filename);
            else
                await shareOrDownload(
                    response.data,
                    filename,
                    t("customer_statement"),
                );
        } catch (error) {
            setMessage({
                error: errorMessage(error, t("download_failed")),
                success: "",
            });
        }
    };
    return (
        <div className="screen customer-layout">
            <section className="panel customer-sidebar stack">
                <div className="section-heading">
                    <h2>{t("customers")}</h2>
                    {hasPermission(permissions, "create_edit_customers") && (
                        <button
                            className="primary compact"
                            onClick={() => {
                                setCustomerForm({ ...emptyCustomerForm });
                                setShowCreate(!showCreate);
                            }}
                        >
                            ＋ {t("new_customer")}
                        </button>
                    )}
                </div>
                <form
                    className="search"
                    onSubmit={(event) => {
                        event.preventDefault();
                        loadCustomers(query);
                    }}
                >
                    <input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={t("search_name_phone")}
                    />
                    <button>{t("search")}</button>
                </form>
                {showCreate && (
                    <Modal
                        title={
                            customerForm.id
                                ? t("edit_customer")
                                : t("new_customer")
                        }
                        onClose={() => setShowCreate(false)}
                    >
                        <form
                            className="inset-form stack"
                            onSubmit={saveCustomer}
                        >
                            <label>
                                {t("name")}
                                <input
                                    value={customerForm.name}
                                    onChange={(event) =>
                                        setCustomerForm({
                                            ...customerForm,
                                            name: event.target.value,
                                        })
                                    }
                                    required
                                />
                            </label>
                            <label>
                                {t("phone")}
                                <input
                                    inputMode="tel"
                                    value={customerForm.phone_number}
                                    onChange={(event) =>
                                        setCustomerForm({
                                            ...customerForm,
                                            phone_number: event.target.value,
                                        })
                                    }
                                />
                            </label>
                            <label>
                                {t("address_note")}
                                <textarea
                                    value={customerForm.address_or_note}
                                    onChange={(event) =>
                                        setCustomerForm({
                                            ...customerForm,
                                            address_or_note: event.target.value,
                                        })
                                    }
                                />
                            </label>
                            <div className="two-column">
                                <label>
                                    {t("opening_balance")}
                                    <input
                                        type="number"
                                        inputMode="numeric"
                                        value={
                                            customerForm.opening_balance_kyat
                                        }
                                        onChange={(event) =>
                                            setCustomerForm({
                                                ...customerForm,
                                                opening_balance_kyat:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <label>
                                    {t("reason")}
                                    <input
                                        value={
                                            customerForm.opening_balance_reason
                                        }
                                        onChange={(event) =>
                                            setCustomerForm({
                                                ...customerForm,
                                                opening_balance_reason:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </label>
                            </div>
                            <button className="primary">{t("save")}</button>
                        </form>
                    </Modal>
                )}
                <div className="customer-list">
                    {customers.map((customer) => (
                        <button
                            className={
                                selected?.id === customer.id
                                    ? "customer-row selected"
                                    : "customer-row"
                            }
                            key={customer.id}
                            onClick={() => setSelected(customer)}
                        >
                            <span>
                                <strong>{customer.name}</strong>
                                <small>
                                    {customer.phone_number || t("no_phone")}
                                </small>
                            </span>
                            <Balance
                                value={customer.current_balance_kyat}
                                t={t}
                            />
                        </button>
                    ))}
                </div>
            </section>
            <section className="panel stack customer-detail">
                <Notice kind="error">{message.error}</Notice>
                <Notice kind="success">{message.success}</Notice>
                {!selected ? (
                    <Empty t={t} />
                ) : (
                    <>
                        <div className="section-heading">
                            <div>
                                <p className="eyebrow">{t("customer")}</p>
                                <h2>{selected.name}</h2>
                                <small>
                                    {selected.phone_number || t("no_phone")}
                                </small>
                            </div>
                            <details className="inline-disclosure">
                                <summary className="secondary compact">
                                    {t("more")}
                                </summary>
                                <div className="button-row">
                                    {hasPermission(
                                        permissions,
                                        "view_customer_statements",
                                    ) && (
                                        <button
                                            className="secondary compact"
                                            onClick={() => statement("share")}
                                        >
                                            {t("share_statement")}
                                        </button>
                                    )}
                                    {hasPermission(
                                        permissions,
                                        "view_customer_statements",
                                    ) && (
                                        <button
                                            className="secondary compact"
                                            onClick={() => statement("save")}
                                        >
                                            {t("save_statement")}
                                        </button>
                                    )}
                                    {hasPermission(
                                        permissions,
                                        "create_edit_customers",
                                    ) && (
                                        <button
                                            className="secondary compact"
                                            onClick={editCustomer}
                                        >
                                            {t("edit")}
                                        </button>
                                    )}
                                    {hasPermission(
                                        permissions,
                                        "create_edit_customers",
                                    ) && (
                                        <button
                                            className="danger-link"
                                            onClick={archiveCustomer}
                                        >
                                            {t("archive")}
                                        </button>
                                    )}
                                </div>
                            </details>
                        </div>
                        <Balance
                            large
                            value={selected.current_balance_kyat}
                            t={t}
                        />
                        <div className="action-grid">
                            {hasPermission(permissions, "create_sale") && (
                                <button
                                    className="primary"
                                    onClick={() => onNewSale(selected.id)}
                                >
                                    ＋ {t("new_sale")}
                                </button>
                            )}
                            {hasPermission(
                                permissions,
                                "record_customer_payment",
                            ) && (
                                <button
                                    className="secondary"
                                    onClick={() =>
                                        setMoneyForm({
                                            ...moneyForm,
                                            type: "payment",
                                            occurred_at: hasPermission(
                                                permissions,
                                                "backdate_sale",
                                            )
                                                ? nowForInput()
                                                : "",
                                        })
                                    }
                                >
                                    {t("customer_pays_shop")}
                                </button>
                            )}
                            {hasPermission(
                                permissions,
                                "record_money_given_lent",
                            ) && (
                                <button
                                    className="secondary"
                                    onClick={() =>
                                        setMoneyForm({
                                            ...moneyForm,
                                            type: "lent",
                                            occurred_at: hasPermission(
                                                permissions,
                                                "backdate_sale",
                                            )
                                                ? nowForInput()
                                                : "",
                                        })
                                    }
                                >
                                    {t("customer_receives_money")}
                                </button>
                            )}
                        </div>
                        {moneyForm.type && (
                            <Modal
                                title={
                                    moneyForm.type === "payment"
                                        ? t("customer_pays_shop")
                                        : t("customer_receives_money")
                                }
                                onClose={() =>
                                    setMoneyForm({
                                        ...moneyForm,
                                        type: "",
                                        occurred_at: "",
                                    })
                                }
                            >
                                <form
                                    className="inset-form stack"
                                    onSubmit={recordMoney}
                                >
                                    <div className="two-column">
                                        <label>
                                            {t("amount")}
                                            <input
                                                type="number"
                                                min="1"
                                                inputMode="numeric"
                                                value={moneyForm.amount_kyat}
                                                onChange={(event) =>
                                                    setMoneyForm({
                                                        ...moneyForm,
                                                        amount_kyat:
                                                            event.target.value,
                                                    })
                                                }
                                                required
                                            />
                                        </label>
                                        <label>
                                            {t("reason")}
                                            <input
                                                value={moneyForm.reason}
                                                onChange={(event) =>
                                                    setMoneyForm({
                                                        ...moneyForm,
                                                        reason: event.target
                                                            .value,
                                                    })
                                                }
                                                required
                                            />
                                        </label>
                                    </div>
                                    <label>
                                        {t("note")}
                                        <textarea
                                            value={moneyForm.note}
                                            onChange={(event) =>
                                                setMoneyForm({
                                                    ...moneyForm,
                                                    note: event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                    {hasPermission(
                                        permissions,
                                        "backdate_sale",
                                    ) && (
                                        <label>
                                            {t("date_time")}
                                            <input
                                                type="datetime-local"
                                                value={moneyForm.occurred_at}
                                                max={nowForInput()}
                                                onChange={(event) =>
                                                    setMoneyForm({
                                                        ...moneyForm,
                                                        occurred_at:
                                                            event.target.value,
                                                    })
                                                }
                                                required
                                            />
                                        </label>
                                    )}
                                    <div className="button-row">
                                        <button
                                            type="button"
                                            className="ghost"
                                            onClick={() =>
                                                setMoneyForm({
                                                    ...moneyForm,
                                                    type: "",
                                                    occurred_at: "",
                                                })
                                            }
                                        >
                                            {t("cancel")}
                                        </button>
                                        <button
                                            className="primary"
                                            disabled={moneySaving}
                                        >
                                            {moneySaving
                                                ? t("saving")
                                                : t("save")}
                                        </button>
                                    </div>
                                </form>
                            </Modal>
                        )}
                        {correctionForm && (
                            <Modal
                                title={t("correct_ledger_entry")}
                                onClose={() => setCorrectionForm(null)}
                            >
                                <form
                                    className="inset-form stack"
                                    onSubmit={correctEntry}
                                >
                                    <p className="muted">
                                        {t(
                                            `event_${correctionForm.event_type}`,
                                        )}
                                    </p>
                                    <div className="two-column">
                                        <label>
                                            {t("corrected_amount")}
                                            <input
                                                type="number"
                                                min="1"
                                                inputMode="numeric"
                                                value={
                                                    correctionForm.amount_kyat
                                                }
                                                onChange={(event) =>
                                                    setCorrectionForm({
                                                        ...correctionForm,
                                                        amount_kyat:
                                                            event.target.value,
                                                    })
                                                }
                                                required
                                            />
                                        </label>
                                        <label>
                                            {t("correction_reason")}
                                            <input
                                                value={correctionForm.reason}
                                                onChange={(event) =>
                                                    setCorrectionForm({
                                                        ...correctionForm,
                                                        reason: event.target
                                                            .value,
                                                    })
                                                }
                                                required
                                            />
                                        </label>
                                    </div>
                                    {hasPermission(
                                        permissions,
                                        "backdate_sale",
                                    ) && (
                                        <label>
                                            {t("date_time")}
                                            <input
                                                type="datetime-local"
                                                value={
                                                    correctionForm.occurred_at
                                                }
                                                max={nowForInput()}
                                                onChange={(event) =>
                                                    setCorrectionForm({
                                                        ...correctionForm,
                                                        occurred_at:
                                                            event.target.value,
                                                    })
                                                }
                                                required
                                            />
                                        </label>
                                    )}
                                    <label>
                                        {t("note")}
                                        <textarea
                                            value={correctionForm.note}
                                            onChange={(event) =>
                                                setCorrectionForm({
                                                    ...correctionForm,
                                                    note: event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                    <div className="button-row">
                                        <button
                                            type="button"
                                            className="ghost"
                                            onClick={() =>
                                                setCorrectionForm(null)
                                            }
                                        >
                                            {t("cancel")}
                                        </button>
                                        <button
                                            className="primary"
                                            disabled={correctionSaving}
                                        >
                                            {correctionSaving
                                                ? t("saving")
                                                : t("save_entry_correction")}
                                        </button>
                                    </div>
                                </form>
                            </Modal>
                        )}
                        {hasPermission(
                            permissions,
                            "view_customer_statements",
                        ) && (
                            <>
                                <button
                                    type="button"
                                    className="secondary compact"
                                    onClick={() => setShowLedgerFilters(true)}
                                >
                                    {t("filters")}
                                </button>
                                {showLedgerFilters && (
                                    <Modal
                                        title={t("filters")}
                                        onClose={() =>
                                            setShowLedgerFilters(false)
                                        }
                                    >
                                        <form
                                            className="filter-grid ledger-filter"
                                            onSubmit={applyLedgerFilter}
                                        >
                                            <label>
                                                {t("from")}
                                                <input
                                                    type="date"
                                                    value={ledgerRange.from}
                                                    onChange={(event) =>
                                                        setLedgerRange(
                                                            (current) => ({
                                                                ...current,
                                                                from: event
                                                                    .target
                                                                    .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                            </label>
                                            <label>
                                                {t("to")}
                                                <input
                                                    type="date"
                                                    value={ledgerRange.to}
                                                    onChange={(event) =>
                                                        setLedgerRange(
                                                            (current) => ({
                                                                ...current,
                                                                to: event.target
                                                                    .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                            </label>
                                            <button
                                                className="secondary"
                                                type="submit"
                                            >
                                                {t("apply_filter")}
                                            </button>
                                            <button
                                                className="ghost"
                                                type="button"
                                                onClick={() => {
                                                    setLedgerRange({
                                                        from: "",
                                                        to: "",
                                                    });
                                                    setAppliedLedgerRange({
                                                        from: "",
                                                        to: "",
                                                    });
                                                    setShowLedgerFilters(false);
                                                }}
                                            >
                                                {t("clear_filter")}
                                            </button>
                                        </form>
                                    </Modal>
                                )}
                            </>
                        )}
                        <div className="section-heading">
                            <h3>{t("activity")}</h3>
                            <span className="pill">{ledger.length}</span>
                        </div>
                        {ledger.length ? (
                            <div className="timeline">
                                {ledger.map((entry) => (
                                    <article
                                        className="timeline-entry"
                                        key={entry.id}
                                    >
                                        <span
                                            className={`timeline-dot ${entry.amount_kyat >= 0 ? "positive" : "negative"}`}
                                        />
                                        <div>
                                            <strong>
                                                {t(`event_${entry.event_type}`)}
                                            </strong>
                                            <small>
                                                {new Date(
                                                    entry.occurred_at,
                                                ).toLocaleString()}
                                            </small>
                                            {entry.reason && (
                                                <small>{entry.reason}</small>
                                            )}
                                            {entry.meta?.note && (
                                                <small>{entry.meta.note}</small>
                                            )}
                                            {entry.actor?.name && (
                                                <small>
                                                    {t("recorded_by")}:{" "}
                                                    {entry.actor.name}
                                                </small>
                                            )}
                                        </div>
                                        <div className="align-right">
                                            <strong>
                                                {entry.amount_kyat >= 0
                                                    ? "+"
                                                    : "−"}
                                                {money(
                                                    Math.abs(entry.amount_kyat),
                                                )}
                                            </strong>
                                            <small>
                                                {t("balance")}:{" "}
                                                {money(
                                                    entry.balance_after_kyat,
                                                )}
                                            </small>
                                            {entry.reversed_by?.length > 0 && (
                                                <small className="danger-text">
                                                    {t("reversed_status")}
                                                </small>
                                            )}
                                            {hasPermission(
                                                permissions,
                                                "correct_reverse_ledger",
                                            ) &&
                                                [
                                                    "customer_paid",
                                                    "money_lent",
                                                    "opening_balance_adjustment",
                                                ].includes(entry.event_type) &&
                                                !entry.reversed_by?.length && (
                                                    <div className="button-row compact-actions">
                                                        {[
                                                            "customer_paid",
                                                            "money_lent",
                                                        ].includes(
                                                            entry.event_type,
                                                        ) && (
                                                            <button
                                                                className="secondary compact"
                                                                onClick={() =>
                                                                    beginEntryCorrection(
                                                                        entry,
                                                                    )
                                                                }
                                                            >
                                                                {t("edit")}
                                                            </button>
                                                        )}
                                                        <button
                                                            className="danger-link"
                                                            onClick={() =>
                                                                reverseEntry(
                                                                    entry,
                                                                )
                                                            }
                                                        >
                                                            {t("reverse")}
                                                        </button>
                                                    </div>
                                                )}
                                        </div>
                                    </article>
                                ))}
                            </div>
                        ) : (
                            <Empty t={t} />
                        )}
                    </>
                )}
            </section>
        </div>
    );
}

function ReportsScreen({ t }) {
    const [showFilters, setShowFilters] = useState(false);
    const [filters, setFilters] = useState({
        range: "today",
        from: "",
        to: "",
        customer_id: "",
        curry_category_id: "",
        curry_item_id: "",
        paid_status: "",
    });
    const [options, setOptions] = useState({
        customers: [],
        categories: [],
        curries: [],
    });
    const [data, setData] = useState({
        summary: null,
        balances: null,
        curries: null,
    });
    const [error, setError] = useState("");
    const params = useMemo(
        () =>
            Object.fromEntries(
                Object.entries(filters).filter(([, value]) => value !== ""),
            ),
        [filters],
    );
    const load = useCallback(async () => {
        setError("");
        try {
            const [summary, balances, curries] = await Promise.all([
                apiClient.get("/reports/sales-summary", { params }),
                apiClient.get("/reports/customer-balances"),
                apiClient.get("/reports/top-curries", { params }),
            ]);
            setData({
                summary: summary.data,
                balances: balances.data,
                curries: curries.data,
            });
        } catch (error) {
            setError(errorMessage(error, t("load_failed")));
        }
    }, [params, t]);
    useEffect(() => {
        apiClient
            .get("/reports/filter-options")
            .then((response) => setOptions(response.data))
            .catch((error) => setError(errorMessage(error, t("load_failed"))));
    }, [t]);
    useEffect(() => {
        load();
    }, [load]);
    const metrics = [
        ["total_sales", "total_sales", "money"],
        ["total_discounts", "total_discounts", "money"],
        ["paid_at_sale", "total_paid_at_sale", "money"],
        ["new_sale_debt", "total_new_sale_debt", "money"],
        ["payments_received", "customer_payments_received", "money"],
        ["money_lent", "money_lent_or_returned", "money"],
        ["reversed_sales", "reversed_sales_count", "count"],
        ["reversed_adjustments", "reversed_ledger_entries_count", "count"],
    ];
    const change = (field, value) =>
        setFilters((current) => ({
            ...current,
            [field]: value,
            ...(field === "range" && value !== "custom"
                ? { from: "", to: "" }
                : {}),
        }));
    return (
        <div className="screen stack-lg">
            <section className="panel stack">
                <div className="section-heading">
                    <h2>{t("reports")}</h2>
                    <div className="button-row">
                        <button
                            className="secondary compact"
                            onClick={() => setShowFilters(true)}
                        >
                            {t("filters")}
                        </button>
                        <button className="text-button" onClick={load}>
                            {t("refresh")}
                        </button>
                    </div>
                </div>
                <small>
                    {t("date_range")}: {t(filters.range)}
                </small>
                {showFilters && (
                    <Modal
                        title={t("filters")}
                        onClose={() => setShowFilters(false)}
                        wide
                    >
                        <div className="filter-grid modal-filter-grid">
                            <label>
                                {t("date_range")}
                                <select
                                    value={filters.range}
                                    onChange={(event) =>
                                        change("range", event.target.value)
                                    }
                                >
                                    <option value="today">{t("today")}</option>
                                    <option value="yesterday">
                                        {t("yesterday")}
                                    </option>
                                    <option value="this_week">
                                        {t("this_week")}
                                    </option>
                                    <option value="this_month">
                                        {t("this_month")}
                                    </option>
                                    <option value="custom">
                                        {t("custom")}
                                    </option>
                                </select>
                            </label>
                            {filters.range === "custom" && (
                                <>
                                    <label>
                                        {t("from")}
                                        <input
                                            type="date"
                                            value={filters.from}
                                            onChange={(event) =>
                                                change(
                                                    "from",
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                    <label>
                                        {t("to")}
                                        <input
                                            type="date"
                                            value={filters.to}
                                            onChange={(event) =>
                                                change("to", event.target.value)
                                            }
                                        />
                                    </label>
                                </>
                            )}
                            <label>
                                {t("customer")}
                                <select
                                    value={filters.customer_id}
                                    onChange={(event) =>
                                        change(
                                            "customer_id",
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">{t("all")}</option>
                                    {options.customers.map((customer) => (
                                        <option
                                            key={customer.id}
                                            value={customer.id}
                                        >
                                            {customer.name}
                                            {customer.is_archived
                                                ? ` (${t("archived")})`
                                                : ""}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                {t("category")}
                                <select
                                    value={filters.curry_category_id}
                                    onChange={(event) =>
                                        change(
                                            "curry_category_id",
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">{t("all")}</option>
                                    {options.categories.map((category) => (
                                        <option
                                            key={category.id}
                                            value={category.id}
                                        >
                                            {category.name}
                                            {!category.is_active
                                                ? ` (${t("inactive")})`
                                                : ""}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                {t("curry")}
                                <select
                                    value={filters.curry_item_id}
                                    onChange={(event) =>
                                        change(
                                            "curry_item_id",
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">{t("all")}</option>
                                    {options.curries.map((curry) => (
                                        <option key={curry.id} value={curry.id}>
                                            {curry.name}
                                            {curry.is_archived
                                                ? ` (${t("archived")})`
                                                : ""}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                {t("payment_status")}
                                <select
                                    value={filters.paid_status}
                                    onChange={(event) =>
                                        change(
                                            "paid_status",
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">{t("all")}</option>
                                    <option value="fully_paid">
                                        {t("fully_paid")}
                                    </option>
                                    <option value="partially_paid">
                                        {t("partially_paid")}
                                    </option>
                                    <option value="unpaid">
                                        {t("unpaid")}
                                    </option>
                                </select>
                            </label>
                        </div>
                        <div className="button-row modal-actions">
                            <button
                                className="primary"
                                onClick={() => setShowFilters(false)}
                            >
                                {t("apply_filters")}
                            </button>
                        </div>
                    </Modal>
                )}
                <Notice kind="error">{error}</Notice>
                <div className="report-grid">
                    {metrics.map(([label, field, format]) => (
                        <article className="stat-card" key={field}>
                            <span>{t(label)}</span>
                            <strong>
                                {format === "money"
                                    ? money(data.summary?.[field])
                                    : Number(data.summary?.[field] || 0)}
                            </strong>
                        </article>
                    ))}
                </div>
            </section>
            <section className="panel">
                <h3>{t("customer_balances")}</h3>
                <div className="stat-grid">
                    <article className="stat-card red">
                        <span>{t("customers_owe")}</span>
                        <strong>
                            {money(data.balances?.total_outstanding)}
                        </strong>
                    </article>
                    <article className="stat-card green">
                        <span>{t("shop_owes")}</span>
                        <strong>{money(data.balances?.total_shop_owes)}</strong>
                    </article>
                </div>
            </section>
            <details className="panel collapsible-panel">
                <summary className="compact-summary">
                    {t("top_curries")}
                </summary>
                <div className="stat-grid">
                    <article className="stat-card orange">
                        <span>{t("most_sold_quantity")}</span>
                        <strong>
                            {data.curries?.most_sold_curry_by_quantity?.name ||
                                "—"}
                        </strong>
                        <small>
                            {data.curries?.most_sold_curry_by_quantity?.value ||
                                0}
                        </small>
                    </article>
                    <article className="stat-card">
                        <span>{t("highest_sales_value")}</span>
                        <strong>
                            {data.curries?.most_sold_curry_by_value?.name ||
                                "—"}
                        </strong>
                        <small>
                            {money(
                                data.curries?.most_sold_curry_by_value?.value,
                            )}
                        </small>
                    </article>
                </div>
            </details>
        </div>
    );
}

function SalesManager({ t, permissions, online }) {
    const [sales, setSales] = useState([]);
    const [customers, setCustomers] = useState([]);
    const [curries, setCurries] = useState([]);
    const [editing, setEditing] = useState(null);
    const [error, setError] = useState("");
    const load = useCallback(async () => {
        try {
            const salesResponse = await apiClient.get("/sales");
            setSales(salesResponse.data);
            if (hasPermission(permissions, "edit_sale")) {
                const options = await apiClient.get("/sales/edit-options");
                setCustomers(options.data.customers);
                setCurries(options.data.curries);
            }
        } catch (error) {
            setError(errorMessage(error, t("load_failed")));
        }
    }, [permissions, t]);
    useEffect(() => {
        load();
    }, [load]);
    const receipt = async (sale) => {
        try {
            const response = await apiClient.get(`/sales/${sale.id}/receipt`, {
                responseType: "blob",
            });
            await shareOrDownload(
                response.data,
                `receipt-${sale.invoice_number}.pdf`,
                t("receipt"),
            );
        } catch (error) {
            setError(errorMessage(error, t("download_failed")));
        }
    };
    const reverse = async (sale) => {
        if (!online) {
            setError(t("offline_warning"));
            return;
        }
        const reason = window.prompt(t("enter_reversal_reason"));
        if (!reason) return;
        try {
            await apiClient.post(`/sales/${sale.id}/reverse`, { reason });
            load();
        } catch (error) {
            setError(errorMessage(error, t("save_failed")));
        }
    };
    const beginEdit = (sale) =>
        setEditing({
            id: sale.id,
            customer_id: sale.customer_id || "",
            is_walk_in: sale.is_walk_in,
            sale_at: new Date(sale.sale_at).toISOString().slice(0, 16),
            discount_kyat: sale.discount_kyat,
            paid_kyat: sale.paid_kyat,
            note: sale.note || "",
            reason: "",
            items: sale.items.map((item) => ({
                curry_item_id: item.curry_item_id,
                quantity: item.quantity,
            })),
        });
    const setItemQuantity = (index, value) =>
        setEditing((current) => ({
            ...current,
            items: current.items.map((item, itemIndex) =>
                itemIndex === index
                    ? { ...item, quantity: Math.max(1, Number(value)) }
                    : item,
            ),
        }));
    const saveEdit = async (event) => {
        event.preventDefault();
        if (!online) {
            setError(t("offline_warning"));
            return;
        }
        setError("");
        try {
            await apiClient.put(`/sales/${editing.id}`, {
                ...editing,
                customer_id: editing.is_walk_in
                    ? null
                    : Number(editing.customer_id),
                sale_at: new Date(editing.sale_at).toISOString(),
                discount_kyat: Number(editing.discount_kyat),
                paid_kyat: Number(editing.paid_kyat),
                items: editing.items.map((item) => ({
                    curry_item_id: Number(item.curry_item_id),
                    quantity: Number(item.quantity),
                })),
            });
            setEditing(null);
            load();
        } catch (error) {
            setError(errorMessage(error, t("save_failed")));
        }
    };
    return (
        <section className="panel stack">
            <div className="section-heading">
                <h2>{t("sales_history")}</h2>
                <button className="text-button" onClick={load}>
                    {t("refresh")}
                </button>
            </div>
            <Notice kind="error">{error}</Notice>
            {editing && (
                <Modal
                    title={t("edit_sale")}
                    onClose={() => setEditing(null)}
                    wide
                >
                    <form className="inset-form stack" onSubmit={saveEdit}>
                        <label className="check-row">
                            <input
                                type="checkbox"
                                checked={editing.is_walk_in}
                                onChange={(event) =>
                                    setEditing({
                                        ...editing,
                                        is_walk_in: event.target.checked,
                                        customer_id: "",
                                    })
                                }
                            />
                            {t("walkin")}
                        </label>
                        {!editing.is_walk_in && (
                            <label>
                                {t("customer")}
                                <select
                                    value={editing.customer_id}
                                    onChange={(event) =>
                                        setEditing({
                                            ...editing,
                                            customer_id: event.target.value,
                                        })
                                    }
                                    required
                                >
                                    <option value="">
                                        {t("select_customer")}
                                    </option>
                                    {customers.map((customer) => (
                                        <option
                                            key={customer.id}
                                            value={customer.id}
                                        >
                                            {customer.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <div className="list">
                            {editing.items.map((item, index) => (
                                <div
                                    className="order-row"
                                    key={`${item.curry_item_id}-${index}`}
                                >
                                    <select
                                        value={item.curry_item_id}
                                        onChange={(event) =>
                                            setEditing((current) => ({
                                                ...current,
                                                items: current.items.map(
                                                    (line, itemIndex) =>
                                                        itemIndex === index
                                                            ? {
                                                                  ...line,
                                                                  curry_item_id:
                                                                      event
                                                                          .target
                                                                          .value,
                                                              }
                                                            : line,
                                                ),
                                            }))
                                        }
                                    >
                                        {curries.map((curry) => (
                                            <option
                                                key={curry.id}
                                                value={curry.id}
                                            >
                                                {curry.name}
                                            </option>
                                        ))}
                                    </select>
                                    <input
                                        type="number"
                                        min="1"
                                        value={item.quantity}
                                        onChange={(event) =>
                                            setItemQuantity(
                                                index,
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                        <div className="two-column">
                            <label>
                                {t("discount")}
                                <input
                                    type="number"
                                    min="0"
                                    value={editing.discount_kyat}
                                    onChange={(event) =>
                                        setEditing({
                                            ...editing,
                                            discount_kyat: event.target.value,
                                        })
                                    }
                                />
                            </label>
                            <label>
                                {t("paid_amount")}
                                <input
                                    type="number"
                                    min="0"
                                    value={editing.paid_kyat}
                                    onChange={(event) =>
                                        setEditing({
                                            ...editing,
                                            paid_kyat: event.target.value,
                                        })
                                    }
                                />
                            </label>
                        </div>
                        <label>
                            {t("sale_date_time")}
                            <input
                                type="datetime-local"
                                value={editing.sale_at}
                                onChange={(event) =>
                                    setEditing({
                                        ...editing,
                                        sale_at: event.target.value,
                                    })
                                }
                            />
                        </label>
                        <label>
                            {t("note")}
                            <textarea
                                value={editing.note}
                                onChange={(event) =>
                                    setEditing({
                                        ...editing,
                                        note: event.target.value,
                                    })
                                }
                            />
                        </label>
                        <label>
                            {t("correction_reason")}
                            <input
                                value={editing.reason}
                                onChange={(event) =>
                                    setEditing({
                                        ...editing,
                                        reason: event.target.value,
                                    })
                                }
                                required
                            />
                        </label>
                        <button className="primary">
                            {t("save_correction")}
                        </button>
                    </form>
                </Modal>
            )}
            {sales.length ? (
                <div className="list">
                    {sales.map((sale) => (
                        <article className="sale-card" key={sale.id}>
                            <div>
                                <strong>{sale.invoice_number}</strong>
                                <small>
                                    {sale.customer?.name || t("walkin")} ·{" "}
                                    {new Date(sale.sale_at).toLocaleString()}
                                </small>
                                <small>
                                    {sale.items
                                        .map(
                                            (item) =>
                                                `${item.curry_name_snapshot} × ${item.quantity}`,
                                        )
                                        .join(", ")}
                                </small>
                            </div>
                            <div className="align-right">
                                <strong>{money(sale.total_kyat)}</strong>
                                <small>
                                    {t("paid_amount")}: {money(sale.paid_kyat)}
                                </small>
                                <div className="button-row">
                                    <button
                                        className="secondary compact"
                                        onClick={() => receipt(sale)}
                                    >
                                        {t("receipt")}
                                    </button>
                                    {hasPermission(
                                        permissions,
                                        "edit_sale",
                                    ) && (
                                        <button
                                            className="secondary compact"
                                            onClick={() => beginEdit(sale)}
                                        >
                                            {t("edit")}
                                        </button>
                                    )}
                                    {hasPermission(
                                        permissions,
                                        "delete_reverse_sale",
                                    ) && (
                                        <button
                                            className="danger compact"
                                            onClick={() => reverse(sale)}
                                        >
                                            {t("reverse")}
                                        </button>
                                    )}
                                </div>
                            </div>
                            <details className="sale-details">
                                <summary>{t("sale_details")}</summary>
                                <div className="stack compact-stack">
                                    {sale.items.map((item) => (
                                        <div className="list-row" key={item.id}>
                                            <span>
                                                <strong>
                                                    {item.curry_name_snapshot}
                                                </strong>
                                                <small>
                                                    {t("quantity")}:{" "}
                                                    {item.quantity}
                                                    {" · "}
                                                    {t("unit_price")}:{" "}
                                                    {money(
                                                        item.unit_price_snapshot_kyat,
                                                    )}
                                                </small>
                                            </span>
                                            <strong>
                                                {money(item.line_total_kyat)}
                                            </strong>
                                        </div>
                                    ))}
                                    <small>
                                        {t("subtotal")}:{" "}
                                        {money(sale.subtotal_kyat)}
                                    </small>
                                    <small>
                                        {t("discount")}:{" "}
                                        {money(sale.discount_kyat)}
                                    </small>
                                    <small>
                                        {t("paid_amount")}:{" "}
                                        {money(sale.paid_kyat)}
                                    </small>
                                    <small>
                                        {sale.unpaid_kyat < 0
                                            ? t("customer_credit")
                                            : t("unpaid")}
                                        : {money(Math.abs(sale.unpaid_kyat))}
                                    </small>
                                    <small>
                                        {t("note")}: {sale.note || "—"}
                                    </small>
                                </div>
                            </details>
                        </article>
                    ))}
                </div>
            ) : (
                <Empty t={t} />
            )}
        </section>
    );
}

function CurryManager({ t }) {
    const [categories, setCategories] = useState([]);
    const [items, setItems] = useState([]);
    const [showCategoryForm, setShowCategoryForm] = useState(false);
    const [showItemForm, setShowItemForm] = useState(false);
    const emptyCategoryForm = {
        id: null,
        name: "",
        display_order: 0,
        is_active: true,
    };
    const [categoryForm, setCategoryForm] = useState({
        ...emptyCategoryForm,
    });
    const emptyForm = {
        id: null,
        name: "",
        current_price_kyat: "",
        curry_category_id: "",
        display_order: 0,
        is_available: true,
    };
    const [form, setForm] = useState({ ...emptyForm });
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const load = useCallback(async () => {
        try {
            const [categoriesResponse, itemsResponse] = await Promise.all([
                apiClient.get("/curry-categories"),
                apiClient.get("/curry-items"),
            ]);
            setCategories(categoriesResponse.data);
            setItems(itemsResponse.data);
        } catch (error) {
            setError(errorMessage(error, t("load_failed")));
        }
    }, [t]);
    useEffect(() => {
        load();
    }, [load]);
    const saveCategory = async (event) => {
        event.preventDefault();
        setError("");
        setSuccess("");
        try {
            const payload = {
                name: categoryForm.name,
                display_order: Number(categoryForm.display_order || 0),
                is_active: categoryForm.is_active,
            };
            if (categoryForm.id) {
                await apiClient.put(
                    `/curry-categories/${categoryForm.id}`,
                    payload,
                );
            } else {
                await apiClient.post("/curry-categories", payload);
            }
            setCategoryForm({ ...emptyCategoryForm });
            setShowCategoryForm(false);
            await load();
            setSuccess(t("changes_saved"));
        } catch (error) {
            setError(errorMessage(error, t("save_failed")));
        }
    };
    const saveItem = async (event) => {
        event.preventDefault();
        setError("");
        setSuccess("");
        try {
            const payload = {
                ...form,
                current_price_kyat: Number(form.current_price_kyat),
                curry_category_id: form.curry_category_id
                    ? Number(form.curry_category_id)
                    : null,
                display_order: Number(form.display_order || 0),
            };
            if (form.id)
                await apiClient.put(`/curry-items/${form.id}`, payload);
            else await apiClient.post("/curry-items", payload);
            setForm({ ...emptyForm });
            setShowItemForm(false);
            await load();
            setSuccess(t("changes_saved"));
        } catch (error) {
            setError(errorMessage(error, t("save_failed")));
        }
    };
    const editItem = (item) => {
        setForm({
            id: item.id,
            name: item.name,
            current_price_kyat: item.current_price_kyat,
            curry_category_id: item.curry_category_id || "",
            display_order: item.display_order || 0,
            is_available: item.is_available,
        });
        setShowItemForm(true);
    };
    const archive = async (item) => {
        if (!window.confirm(t("confirm_archive"))) return;
        setError("");
        setSuccess("");
        try {
            await apiClient.post(`/curry-items/${item.id}/archive`);
            await load();
            setSuccess(t("curry_archived"));
        } catch (error) {
            setError(errorMessage(error, t("save_failed")));
        }
    };
    return (
        <section className="panel stack-lg">
            <div className="section-heading">
                <h2>{t("curry_management")}</h2>
                <div className="button-row">
                    <button
                        className="secondary compact"
                        onClick={() => {
                            setCategoryForm({ ...emptyCategoryForm });
                            setShowCategoryForm(true);
                        }}
                    >
                        {t("new_category")}
                    </button>
                    <button
                        className="primary compact"
                        onClick={() => {
                            setForm({ ...emptyForm });
                            setShowItemForm(true);
                        }}
                    >
                        {t("new_curry")}
                    </button>
                </div>
            </div>
            <Notice kind="error">{error}</Notice>
            <Notice kind="success">{success}</Notice>
            <div className="management-grid">
                {showCategoryForm && (
                    <Modal
                        title={
                            categoryForm.id
                                ? t("edit_category")
                                : t("new_category")
                        }
                        onClose={() => setShowCategoryForm(false)}
                    >
                        <form
                            className="inset-form stack"
                            onSubmit={saveCategory}
                        >
                            <label>
                                {t("category_name")}
                                <input
                                    value={categoryForm.name}
                                    onChange={(event) =>
                                        setCategoryForm({
                                            ...categoryForm,
                                            name: event.target.value,
                                        })
                                    }
                                    required
                                />
                            </label>
                            <label>
                                {t("display_order")}
                                <input
                                    type="number"
                                    min="0"
                                    value={categoryForm.display_order}
                                    onChange={(event) =>
                                        setCategoryForm({
                                            ...categoryForm,
                                            display_order: event.target.value,
                                        })
                                    }
                                />
                            </label>
                            <label className="check-row">
                                <input
                                    type="checkbox"
                                    checked={categoryForm.is_active}
                                    onChange={(event) =>
                                        setCategoryForm({
                                            ...categoryForm,
                                            is_active: event.target.checked,
                                        })
                                    }
                                />
                                {t("active")}
                            </label>
                            <button className="secondary">{t("save")}</button>
                            <div className="list">
                                {categories.map((category) => (
                                    <div className="list-row" key={category.id}>
                                        <div>
                                            <strong>{category.name}</strong>
                                            <small>
                                                {category.is_active
                                                    ? t("active")
                                                    : t("inactive")}{" "}
                                                · #{category.display_order}
                                            </small>
                                        </div>
                                        <button
                                            type="button"
                                            className="text-button"
                                            onClick={() => {
                                                setCategoryForm({
                                                    id: category.id,
                                                    name: category.name,
                                                    display_order:
                                                        category.display_order ||
                                                        0,
                                                    is_active:
                                                        category.is_active,
                                                });
                                                setShowCategoryForm(true);
                                            }}
                                        >
                                            {t("edit")}
                                        </button>
                                    </div>
                                ))}
                            </div>
                        </form>
                    </Modal>
                )}
                {showItemForm && (
                    <Modal
                        title={form.id ? t("edit_curry") : t("new_curry")}
                        onClose={() => setShowItemForm(false)}
                    >
                        <form className="inset-form stack" onSubmit={saveItem}>
                            <label>
                                {t("name")}
                                <input
                                    value={form.name}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            name: event.target.value,
                                        })
                                    }
                                    required
                                />
                            </label>
                            <div className="two-column">
                                <label>
                                    {t("price")}
                                    <input
                                        type="number"
                                        min="0"
                                        inputMode="numeric"
                                        value={form.current_price_kyat}
                                        onChange={(event) =>
                                            setForm({
                                                ...form,
                                                current_price_kyat:
                                                    event.target.value,
                                            })
                                        }
                                        required
                                    />
                                </label>
                                <label>
                                    {t("category")}
                                    <select
                                        value={form.curry_category_id}
                                        onChange={(event) =>
                                            setForm({
                                                ...form,
                                                curry_category_id:
                                                    event.target.value,
                                            })
                                        }
                                    >
                                        <option value="">{t("none")}</option>
                                        {categories.map((category) => (
                                            <option
                                                value={category.id}
                                                key={category.id}
                                            >
                                                {category.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <div className="two-column">
                                <label>
                                    {t("display_order")}
                                    <input
                                        type="number"
                                        min="0"
                                        value={form.display_order}
                                        onChange={(event) =>
                                            setForm({
                                                ...form,
                                                display_order:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <label className="check-row">
                                    <input
                                        type="checkbox"
                                        checked={form.is_available}
                                        onChange={(event) =>
                                            setForm({
                                                ...form,
                                                is_available:
                                                    event.target.checked,
                                            })
                                        }
                                    />
                                    {t("available")}
                                </label>
                            </div>
                            <button className="primary">{t("save")}</button>
                        </form>
                    </Modal>
                )}
            </div>
            <div className="list">
                {items.map((item) => (
                    <div className="list-row" key={item.id}>
                        <div>
                            <strong>{item.name}</strong>
                            <small>
                                {item.category?.name || t("uncategorized")} ·{" "}
                                {item.is_available
                                    ? t("available")
                                    : t("unavailable")}
                            </small>
                        </div>
                        <div className="align-right">
                            <strong>{money(item.current_price_kyat)}</strong>
                            <div className="button-row">
                                {!item.is_archived && (
                                    <button
                                        className="secondary compact"
                                        onClick={() => editItem(item)}
                                    >
                                        {t("edit")}
                                    </button>
                                )}
                                {!item.is_archived && (
                                    <button
                                        className="danger-link"
                                        onClick={() => archive(item)}
                                    >
                                        {t("archive")}
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

function StaffManager({ t }) {
    const [staff, setStaff] = useState([]);
    const [roles, setRoles] = useState([]);
    const [permissions, setPermissions] = useState([]);
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const emptyForm = {
        id: null,
        name: "",
        email: "",
        password: "",
        password_confirmation: "",
        role_ids: [],
        permission_ids: [],
    };
    const [form, setForm] = useState(null);
    const load = useCallback(async () => {
        try {
            const [staffResponse, rolesResponse, permissionsResponse] =
                await Promise.all([
                    apiClient.get("/admin/staff"),
                    apiClient.get("/admin/roles"),
                    apiClient.get("/admin/permissions"),
                ]);
            setStaff(staffResponse.data);
            setRoles(rolesResponse.data);
            setPermissions(permissionsResponse.data);
        } catch (error) {
            setError(errorMessage(error, t("load_failed")));
        }
    }, [t]);
    useEffect(() => {
        load();
    }, [load]);
    const toggle = async (user) => {
        const reason = window.prompt(t("enter_change_reason"));
        if (!reason) return;
        setError("");
        setSuccess("");
        try {
            await apiClient.put(`/admin/users/${user.id}/disabled`, {
                is_disabled: !user.is_disabled,
                reason,
            });
            await load();
            setSuccess(t("changes_saved"));
        } catch (error) {
            setError(errorMessage(error, t("save_failed")));
        }
    };
    const edit = (user) =>
        setForm({
            ...emptyForm,
            id: user.id,
            name: user.name,
            email: user.email,
            role_ids: [],
            permission_ids: [
                ...new Set([
                    ...(user.direct_permissions || []).map(
                        (permission) => permission.id,
                    ),
                    ...(user.roles || []).flatMap((role) =>
                        (role.permissions || []).map(
                            (permission) => permission.id,
                        ),
                    ),
                ]),
            ],
        });
    const toggleArray = (field, id) =>
        setForm((current) => ({
            ...current,
            [field]: current[field].includes(id)
                ? current[field].filter((value) => value !== id)
                : [...current[field], id],
        }));
    const saveStaff = async (event) => {
        event.preventDefault();
        setError("");
        setSuccess("");
        try {
            const payload = {
                name: form.name,
                email: form.email,
                role_ids: form.role_ids,
                permission_ids: form.permission_ids,
            };
            if (form.id) {
                await apiClient.put(`/admin/staff/${form.id}`, payload);
                if (form.password)
                    await apiClient.put(`/admin/staff/${form.id}/password`, {
                        password: form.password,
                        password_confirmation: form.password_confirmation,
                        reason: t("admin_password_reset_reason"),
                    });
            } else {
                await apiClient.post("/admin/staff", {
                    ...payload,
                    password: form.password,
                });
            }
            setForm(null);
            await load();
            setSuccess(t("changes_saved"));
        } catch (error) {
            setError(errorMessage(error, t("save_failed")));
        }
    };
    return (
        <section className="panel stack">
            <div className="section-heading">
                <h2>{t("staff_accounts")}</h2>
                <div className="button-row">
                    <button className="text-button" onClick={load}>
                        {t("refresh")}
                    </button>
                    <button
                        className="primary compact"
                        onClick={() => setForm({ ...emptyForm })}
                    >
                        ＋ {t("new_staff")}
                    </button>
                </div>
            </div>
            <Notice kind="error">{error}</Notice>
            <Notice kind="success">{success}</Notice>
            {form && (
                <Modal
                    title={form.id ? t("edit_staff") : t("new_staff")}
                    onClose={() => setForm(null)}
                    wide
                >
                    <form className="inset-form stack" onSubmit={saveStaff}>
                        <div className="two-column">
                            <label>
                                {t("name")}
                                <input
                                    value={form.name}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            name: event.target.value,
                                        })
                                    }
                                    required
                                />
                            </label>
                            <label>
                                {t("email")}
                                <input
                                    type="email"
                                    value={form.email}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            email: event.target.value,
                                        })
                                    }
                                    required
                                />
                            </label>
                        </div>
                        <div className="two-column">
                            <label>
                                {form.id
                                    ? t("new_password_optional")
                                    : t("password")}
                                <input
                                    type="password"
                                    value={form.password}
                                    minLength="8"
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            password: event.target.value,
                                        })
                                    }
                                    required={!form.id}
                                />
                            </label>
                            {form.id && (
                                <label>
                                    {t("confirm_password")}
                                    <input
                                        type="password"
                                        value={form.password_confirmation}
                                        onChange={(event) =>
                                            setForm({
                                                ...form,
                                                password_confirmation:
                                                    event.target.value,
                                            })
                                        }
                                        required={!!form.password}
                                    />
                                </label>
                            )}
                        </div>
                        <fieldset>
                            <legend>{t("role_templates")}</legend>
                            <div className="template-grid">
                                {roles.map((role) => (
                                    <button
                                        type="button"
                                        className="secondary"
                                        key={role.id}
                                        onClick={() =>
                                            setForm((current) => ({
                                                ...current,
                                                role_ids: [],
                                                permission_ids:
                                                    role.permissions?.map(
                                                        (permission) =>
                                                            permission.id,
                                                    ) || [],
                                            }))
                                        }
                                    >
                                        {localizedRecordLabel(t, "role", role)}
                                    </button>
                                ))}
                            </div>
                            <small>{t("template_help")}</small>
                        </fieldset>
                        <fieldset>
                            <legend>{t("permissions")}</legend>
                            <div className="choice-grid">
                                {permissions.map((permission) => (
                                    <label
                                        className="check-row"
                                        key={permission.id}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={form.permission_ids.includes(
                                                permission.id,
                                            )}
                                            onChange={() =>
                                                toggleArray(
                                                    "permission_ids",
                                                    permission.id,
                                                )
                                            }
                                        />
                                        {localizedRecordLabel(
                                            t,
                                            "permission",
                                            permission,
                                        )}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <button className="primary">{t("save")}</button>
                    </form>
                </Modal>
            )}
            <div className="list">
                {staff.map((user) => (
                    <div className="list-row" key={user.id}>
                        <div>
                            <strong>{user.name}</strong>
                            <small>{user.email}</small>
                            <small>
                                {user.roles
                                    ?.map((role) =>
                                        localizedRecordLabel(t, "role", role),
                                    )
                                    .join(", ") || t("custom_permissions")}
                            </small>
                        </div>
                        <div className="button-row">
                            <button
                                className="secondary compact"
                                onClick={() => edit(user)}
                            >
                                {t("edit")}
                            </button>
                            <button
                                className={
                                    user.is_disabled
                                        ? "secondary compact"
                                        : "danger compact"
                                }
                                onClick={() => toggle(user)}
                            >
                                {user.is_disabled ? t("enable") : t("disable")}
                            </button>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

function AuditManager({ t }) {
    const [entries, setEntries] = useState([]);
    const [error, setError] = useState("");
    const load = useCallback(async () => {
        try {
            setEntries((await apiClient.get("/admin/audit-history")).data);
        } catch (error) {
            setError(errorMessage(error, t("load_failed")));
        }
    }, [t]);
    useEffect(() => {
        load();
    }, [load]);
    return (
        <section className="panel">
            <div className="section-heading">
                <h2>{t("audit_history")}</h2>
                <button className="text-button" onClick={load}>
                    {t("refresh")}
                </button>
            </div>
            <Notice kind="error">{error}</Notice>
            {entries.length ? (
                <div className="list">
                    {entries.map((entry) => (
                        <div className="list-row" key={entry.id}>
                            <div>
                                <strong>{t(`audit_${entry.action}`)}</strong>
                                <small>
                                    {entry.actor?.name || t("system")} ·{" "}
                                    {new Date(
                                        entry.created_at,
                                    ).toLocaleString()}
                                </small>
                                {entry.reason && <small>{entry.reason}</small>}
                            </div>
                            <small>#{entry.subject_id || "—"}</small>
                        </div>
                    ))}
                </div>
            ) : (
                <Empty t={t} />
            )}
        </section>
    );
}

function MoreScreen({
    t,
    permissions,
    initialPanel,
    onPanelChange,
    online,
    locale,
    onLocale,
    onLogout,
}) {
    const available = useMemo(
        () =>
            [
                hasPermission(permissions, "view_sales_history") && "sales",
                hasPermission(permissions, "manage_curry_items") && "curries",
                hasPermission(permissions, "manage_staff_and_permissions") &&
                    "staff",
                hasPermission(permissions, "view_audit_history") &&
                    "audit_history",
                "settings",
            ].filter(Boolean),
        [permissions],
    );
    const [panel, setPanel] = useState(
        initialPanel && available.includes(initialPanel)
            ? initialPanel
            : available[0],
    );
    useEffect(() => {
        if (initialPanel && available.includes(initialPanel))
            setPanel(initialPanel);
    }, [initialPanel, available]);
    return (
        <div className="screen stack">
            <div className="segmented">
                {available.map((key) => (
                    <button
                        className={panel === key ? "active" : ""}
                        key={key}
                        onClick={() => {
                            setPanel(key);
                            onPanelChange(key);
                        }}
                    >
                        {t(key)}
                    </button>
                ))}
            </div>
            {panel === "sales" && (
                <SalesManager t={t} permissions={permissions} online={online} />
            )}
            {panel === "curries" && <CurryManager t={t} />}
            {panel === "staff" && <StaffManager t={t} />}
            {panel === "audit_history" && <AuditManager t={t} />}
            {panel === "settings" && (
                <section className="panel stack">
                    <div className="section-heading">
                        <h2>{t("settings")}</h2>
                    </div>
                    <fieldset>
                        <legend>{t("language")}</legend>
                        <div className="button-row">
                            <button
                                className={
                                    locale === "en" ? "primary" : "secondary"
                                }
                                onClick={() => onLocale("en")}
                            >
                                {t("language_en")}
                            </button>
                            <button
                                className={
                                    locale === "my" ? "primary" : "secondary"
                                }
                                onClick={() => onLocale("my")}
                            >
                                {t("language_my")}
                            </button>
                        </div>
                    </fieldset>
                    <button className="danger" onClick={onLogout}>
                        {t("logout")}
                    </button>
                </section>
            )}
            {!panel && <Empty t={t} />}
        </div>
    );
}

export default function App() {
    const { locale, changeLocale, t } = useLanguage();
    const [session, setSession] = useState({
        loading: true,
        user: null,
        permissions: [],
    });
    const [auth, setAuth] = useState({ loading: false, error: "" });
    const [route, setRoute] = useState(() =>
        parseAppRoute(window.location.pathname, APP_BASE_PATH),
    );
    const { view, subview } = route;
    const [presetCustomer, setPresetCustomer] = useState(null);
    const [online, setOnline] = useState(navigator.onLine);
    const [updateReady, setUpdateReady] = useState(false);
    const refreshSession = useCallback(async () => {
        try {
            const response = await apiClient.get("/auth/session");
            setApiCsrfToken(response.data.csrf_token);
            const user = response.data.user;
            setSession({
                loading: false,
                user,
                permissions: response.data.permissions || [],
            });
            if (user?.ui_locale) changeLocale(user.ui_locale);
        } catch {
            setSession({ loading: false, user: null, permissions: [] });
        }
    }, [changeLocale]);
    useEffect(() => {
        refreshSession();
        const unauthorized = () =>
            setSession({ loading: false, user: null, permissions: [] });
        const on = () => setOnline(true);
        const off = () => setOnline(false);
        const update = () => setUpdateReady(true);
        window.addEventListener("ledger:unauthorized", unauthorized);
        window.addEventListener("online", on);
        window.addEventListener("offline", off);
        window.addEventListener("ledger:update-ready", update);
        return () => {
            window.removeEventListener("ledger:unauthorized", unauthorized);
            window.removeEventListener("online", on);
            window.removeEventListener("offline", off);
            window.removeEventListener("ledger:update-ready", update);
        };
    }, [refreshSession]);
    useEffect(() => {
        const navigateFromHistory = () =>
            setRoute(parseAppRoute(window.location.pathname, APP_BASE_PATH));
        window.addEventListener("popstate", navigateFromHistory);
        return () =>
            window.removeEventListener("popstate", navigateFromHistory);
    }, []);
    useEffect(() => {
        if (session.loading || !session.user) return;
        const requiredPermission = viewPermissions[route.view];
        if (
            !requiredPermission ||
            hasPermission(session.permissions, requiredPermission)
        )
            return;

        const fallbackView =
            ["home", "new_sale", "customers", "reports"].find((candidate) =>
                hasPermission(session.permissions, viewPermissions[candidate]),
            ) || "more";
        const fallback = { view: fallbackView, subview: null };
        setRoute(fallback);
        window.history.replaceState(
            {},
            "",
            appRoutePath(fallback.view, null, APP_BASE_PATH),
        );
    }, [route.view, session.loading, session.permissions, session.user]);
    const login = async (form) => {
        setAuth({ loading: true, error: "" });
        try {
            const response = await apiClient.post("/auth/login", form);
            setApiCsrfToken(response.data.csrf_token);
            setSession({
                loading: false,
                user: response.data.user,
                permissions: response.data.permissions || [],
            });
            if (response.data.user?.ui_locale)
                changeLocale(response.data.user.ui_locale);
            setAuth({ loading: false, error: "" });
        } catch (error) {
            setAuth({
                loading: false,
                error: errorMessage(error, t("unable_to_login")),
            });
        }
    };
    const logout = async () => {
        try {
            const response = await apiClient.post("/auth/logout");
            setApiCsrfToken(response.data.csrf_token);
        } finally {
            setSession({ loading: false, user: null, permissions: [] });
        }
    };
    const saveLocale = async (next) => {
        changeLocale(next);
        if (session.user) {
            try {
                await apiClient.post("/auth/locale", { ui_locale: next });
            } catch {
                /* local preference remains usable */
            }
        }
    };
    const goTo = (nextView, nextSubview = null) => {
        setRoute({ view: nextView, subview: nextSubview });
        const path = appRoutePath(nextView, nextSubview, APP_BASE_PATH);
        if (window.location.pathname !== path)
            window.history.pushState({}, "", path);
        window.scrollTo({ top: 0, behavior: "smooth" });
    };
    const newSaleFor = (customerId) => {
        setPresetCustomer(customerId);
        goTo("new_sale");
    };
    if (session.loading)
        return (
            <main className="auth-page">
                <Loading t={t} />
            </main>
        );
    if (!session.user)
        return (
            <LoginScreen
                t={t}
                locale={locale}
                onLocale={saveLocale}
                onLogin={login}
                loading={auth.loading}
                error={auth.error}
            />
        );
    const nav = [
        { key: "home", icon: "⌂", permission: "view_dashboard" },
        { key: "new_sale", icon: "＋", permission: "create_sale" },
        { key: "customers", icon: "♙", permission: "view_customers" },
        { key: "reports", icon: "▥", permission: "view_reports" },
        { key: "more", icon: "•••", permission: null },
    ].filter(
        (item) =>
            !item.permission ||
            hasPermission(session.permissions, item.permission),
    );
    const activeView =
        !viewPermissions[view] ||
        hasPermission(session.permissions, viewPermissions[view])
            ? view
            : null;
    return (
        <div className="app-shell">
            <header className="topbar">
                <button
                    className="brand-button"
                    onClick={() =>
                        goTo(
                            hasPermission(session.permissions, "view_dashboard")
                                ? "home"
                                : nav[0]?.key || "more",
                        )
                    }
                >
                    <BrandIcon small />
                    <span>
                        <strong>{t("app_name")}</strong>
                        <small>{t("restaurant_ledger")}</small>
                    </span>
                </button>
                <div className="top-actions">
                    <span
                        className={`online-state ${online ? "online" : "offline"}`}
                    >
                        {online ? t("online") : t("offline")}
                    </span>
                    <button
                        className="ghost compact"
                        onClick={() =>
                            saveLocale(locale === "en" ? "my" : "en")
                        }
                    >
                        {locale.toUpperCase()}
                    </button>
                    <button className="ghost compact" onClick={logout}>
                        {t("logout")}
                    </button>
                </div>
            </header>
            {!online && (
                <div className="offline-banner">{t("offline_warning")}</div>
            )}
            {updateReady && (
                <div className="update-banner">
                    <span>{t("update_ready")}</span>
                    <button onClick={() => window.location.reload()}>
                        {t("reload_now")}
                    </button>
                </div>
            )}
            <main className="content">
                {activeView === "home" && (
                    <HomeScreen
                        t={t}
                        permissions={session.permissions}
                        goTo={goTo}
                    />
                )}
                {activeView === "new_sale" && (
                    <NewSaleScreen
                        t={t}
                        permissions={session.permissions}
                        presetCustomerId={presetCustomer}
                        clearPreset={() => setPresetCustomer(null)}
                        online={online}
                    />
                )}
                {activeView === "customers" && (
                    <CustomersScreen
                        t={t}
                        permissions={session.permissions}
                        initialCustomerId={subview}
                        onNewSale={newSaleFor}
                        online={online}
                    />
                )}
                {activeView === "reports" && <ReportsScreen t={t} />}
                {activeView === "more" && (
                    <MoreScreen
                        t={t}
                        permissions={session.permissions}
                        initialPanel={subview}
                        onPanelChange={(panel) => goTo("more", panel)}
                        online={online}
                        locale={locale}
                        onLocale={saveLocale}
                        onLogout={logout}
                    />
                )}
            </main>
            <nav className="bottom-nav">
                {nav.map((item) => (
                    <button
                        key={item.key}
                        className={
                            activeView === item.key
                                ? `active ${item.key === "new_sale" ? "sale-action" : ""}`
                                : item.key === "new_sale"
                                  ? "sale-action"
                                  : ""
                        }
                        onClick={() => goTo(item.key)}
                    >
                        <span>{item.icon}</span>
                        <small>{t(item.key)}</small>
                    </button>
                ))}
            </nav>
        </div>
    );
}
