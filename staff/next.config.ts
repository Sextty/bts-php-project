import type { NextConfig } from "next";

const isDev = process.env.NODE_ENV === "development";
const apiOrigin = new URL(process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000").origin;
const reverbHost = process.env.NEXT_PUBLIC_REVERB_HOST ?? "127.0.0.1";
const reverbPort = process.env.NEXT_PUBLIC_REVERB_PORT ?? "6001";
const reverbScheme = process.env.NEXT_PUBLIC_REVERB_SCHEME === "https" ? "wss" : "ws";
const csp = [
  "default-src 'self'",
  `script-src 'self' 'unsafe-inline'${isDev ? " 'unsafe-eval'" : ""}`,
  "style-src 'self' 'unsafe-inline'", "img-src 'self' data: blob: https:", "font-src 'self' data:",
  `connect-src 'self' ${apiOrigin} ${reverbScheme}://${reverbHost}:${reverbPort}`,
  "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'",
  ...(isDev ? [] : ["upgrade-insecure-requests"]),
].join("; ");

const nextConfig: NextConfig = {
  distDir: process.env.NEXT_DIST_DIR ?? ".next",
  poweredByHeader: false,
  turbopack: {
    root: process.cwd(),
  },
  // Allow the dev server to be driven from 127.0.0.1 (Playwright E2E suite). Next.js 16
  // blocks cross-origin dev requests (HMR + app resources) from origins other than
  // localhost by default; see node_modules/next/dist/docs/01-app/03-api-reference/05-config/
  // 01-next-config-js/allowedDevOrigins.md
  allowedDevOrigins: ["127.0.0.1"],
  async headers() {
    return [{ source: "/(.*)", headers: [
      { key: "Content-Security-Policy", value: csp },
      { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
      { key: "X-Content-Type-Options", value: "nosniff" },
      { key: "X-Frame-Options", value: "DENY" },
      { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=(), payment=()" },
      { key: "Cross-Origin-Opener-Policy", value: "same-origin" },
    ] }];
  },
};

export default nextConfig;
