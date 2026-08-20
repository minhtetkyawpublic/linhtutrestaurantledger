import "@testing-library/jest-dom/vitest";

Object.defineProperty(window, "scrollTo", {
    configurable: true,
    value: () => {},
});

Object.defineProperty(window, "matchMedia", {
    configurable: true,
    value: () => ({
        matches: false,
        addEventListener: () => {},
        removeEventListener: () => {},
    }),
});
