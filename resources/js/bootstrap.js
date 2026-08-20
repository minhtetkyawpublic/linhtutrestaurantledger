import axios from "axios";
import { APP_API_BASE } from "./utils/runtime-path";
window.axios = axios;

window.axios.defaults.baseURL = APP_API_BASE;
window.axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";

window.__APP_BASE_PATH = APP_API_BASE.replace(/\/api$/, "");
