import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

/** @type {import('next').NextConfig} */
const nextConfig = {
  // The build runs as the `manfaa` service user, whose $HOME is the repo
  // root. Next refuses to infer a workspace root that contains $HOME, falls
  // back to this app's directory, and then cannot resolve the pnpm-linked
  // `next` package — so the monorepo root is pinned explicitly.
  turbopack: { root: resolve(here, '../..') },

  // Optional base path when deployed behind a proxy sub-path (unset = root)
  basePath: process.env.NEXT_PUBLIC_BASE_PATH || '',

  // Asset prefix for static assets
  assetPrefix: process.env.NEXT_PUBLIC_BASE_PATH || '',

  // Workspace packages shipped as TypeScript source
  transpilePackages: ['@manfaa/ui', '@manfaa/api-client'],

  // The old /stores directory merged into /discover. Config-level redirects
  // preserve the query string (q/category/page) by default, so old bookmarks
  // and shared filter links keep working. 308 = permanent.
  async redirects() {
    return [
      {
        source: '/stores',
        destination: '/discover',
        permanent: true,
      },
    ];
  },
};

export default nextConfig;
