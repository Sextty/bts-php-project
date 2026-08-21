import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Allow the dev server to be driven from 127.0.0.1 (Playwright E2E suite). Next.js 16
  // blocks cross-origin dev requests (HMR + app resources) from origins other than
  // localhost by default; see node_modules/next/dist/docs/01-app/03-api-reference/05-config/
  // 01-next-config-js/allowedDevOrigins.md
  allowedDevOrigins: ["127.0.0.1"],
};

export default nextConfig;