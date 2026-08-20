import axios from "axios";
import { APP_API_BASE } from "../utils/runtime-path";

let csrfToken =
    document.querySelector('meta[name="csrf-token"]')?.content ?? "";

const instance = axios.create({
    baseURL: APP_API_BASE,
    headers: {
        "X-Requested-With": "XMLHttpRequest",
    },
    withCredentials: true,
});

export function setApiCsrfToken(token) {
    if (token) csrfToken = token;
}

instance.interceptors.request.use((config) => {
    if (csrfToken) config.headers.set("X-CSRF-TOKEN", csrfToken);
    return config;
});

instance.interceptors.response.use(
    (response) => response,
    (error) => {
        if (
            error?.response?.status === 401 ||
            (error?.response?.status === 403 &&
                error?.response?.data?.message === "Account disabled")
        ) {
            window.dispatchEvent(new CustomEvent("ledger:unauthorized"));
        }

        return Promise.reject(error);
    },
);

export { instance as apiClient };
