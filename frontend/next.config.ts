import type { NextConfig } from "next";

/**
 * Proxy API : toutes les requêtes /api/* sont transmises au backend Laravel
 * (même origine, pas de CORS ni cookie cross-site à gérer). L'URL cible est
 * configurable via BACKEND_URL ; /api reste donc le seul point d'entrée côté
 * navigateur.
 */
const BACKEND_URL = process.env.BACKEND_URL ?? "http://localhost:8000";

const nextConfig: NextConfig = {
  output: "standalone",
  rewrites() {
    return {
      beforeFiles: [
        {
          source: "/api/:path*",
          destination: `${BACKEND_URL}/api/:path*`,
        },
      ],
    };
  },
};

export default nextConfig;
