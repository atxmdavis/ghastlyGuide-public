import { createHash, randomBytes } from "node:crypto";
import type { IncomingMessage, ServerResponse } from "node:http";
import path from "node:path";
import react from "@vitejs/plugin-react";
import { defineConfig, type Plugin } from "vite";

const apiOrigin = process.env.GHASTLY_API_ORIGIN ?? "https://ghastlyguide.com";

function jsonResponse(res: ServerResponse, status: number, payload: unknown): void {
  res.statusCode = status;
  res.setHeader("Content-Type", "application/json; charset=utf-8");
  res.setHeader("Cache-Control", "no-store");
  res.end(JSON.stringify(payload));
}

function readRequestBody(req: IncomingMessage): Promise<string> {
  return new Promise((resolve, reject) => {
    const chunks: Buffer[] = [];
    req.on("data", chunk => chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk)));
    req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
    req.on("error", reject);
  });
}

function savedTransferDevPlugin(): Plugin {
  const store = new Map<string, { payload: unknown; expires: number; claimed: boolean }>();
  return {
    name: "ghastly-saved-transfer-dev",
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        const pathname = req.url?.split("?")[0];
        if (pathname !== "/api/saved-transfer") {
          next();
          return;
        }
        if (req.method !== "POST") {
          jsonResponse(res, 405, { error: "Use POST to copy or claim saved titles." });
          return;
        }
        void readRequestBody(req).then(raw => {
          if (raw.length > 524288) {
            jsonResponse(res, 413, { error: "That list of saves is too large to copy." });
            return;
          }
          const now = Date.now();
          for (const [hash, row] of store) {
            if (row.expires <= now) store.delete(hash);
          }
          const payload = JSON.parse(raw) as { token?: string; saves?: Record<string, unknown> };
          const token = payload.token?.trim() ?? "";
          if (token) {
            const hash = createHash("sha256").update(token).digest("hex");
            const row = store.get(hash);
            if (!row || row.claimed || row.expires <= now) {
              jsonResponse(res, 410, { error: "That copy link expired or was already used." });
              return;
            }
            row.claimed = true;
            jsonResponse(res, 200, { saves: row.payload });
            return;
          }
          const saves = payload.saves;
          if (!saves || typeof saves !== "object" || Array.isArray(saves) || Object.keys(saves).length === 0 || Object.keys(saves).length > 400) {
            jsonResponse(res, 400, { error: "Save at least one title on this device first." });
            return;
          }
          const nextToken = randomBytes(24).toString("hex");
          store.set(createHash("sha256").update(nextToken).digest("hex"), { payload: saves, expires: now + 600_000, claimed: false });
          jsonResponse(res, 201, { token: nextToken, expiresIn: 600 });
        }).catch(() => {
          jsonResponse(res, 500, { error: "Copying saves is temporarily unavailable." });
        });
      });
    },
  };
}

function googleAnalyticsHtmlPlugin(): Plugin {
  return {
    name: "ghastly-google-analytics-html",
    enforce: "pre",
    transformIndexHtml(html) {
      const id = process.env.VITE_GA_MEASUREMENT_ID?.trim() ?? "";
      if (!/^G-[A-Z0-9]+$/i.test(id)) return html;
      // Load the tag for Google's HTML checker, but do not config/page_view here.
      // A config while analytics_storage is denied sends gcs=G100, which GA4 Realtime drops.
      const snippet = `
    <script async src="https://www.googletagmanager.com/gtag/js?id=${id}" data-ghastly-analytics="true"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      var analyticsGranted = false;
      try { analyticsGranted = localStorage.getItem('ghastly-guide:analytics-consent:v1') === 'accepted'; } catch (e) {}
      gtag('consent', 'default', {
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        analytics_storage: analyticsGranted ? 'granted' : 'denied',
        wait_for_update: 500
      });
    </script>`;
      return html.replace('<meta charset="UTF-8" />', `<meta charset="UTF-8" />${snippet}`);
    },
  };
}

export default defineConfig({
  root: path.resolve(import.meta.dirname, "dreamhost/frontend"),
  publicDir: path.resolve(import.meta.dirname, "public"),
  plugins: [react(), googleAnalyticsHtmlPlugin(), savedTransferDevPlugin()],
  server: {
    proxy: {
      "/api": {
        target: apiOrigin,
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: path.resolve(import.meta.dirname, "dist"),
    emptyOutDir: true,
  },
});
