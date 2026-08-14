/** @type {import('next').NextConfig} */
const nextConfig = {
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
