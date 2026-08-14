/** @type {import('next').NextConfig} */
const nextConfig = {
  // Optional base path when deployed behind a proxy sub-path (unset = root)
  basePath: process.env.NEXT_PUBLIC_BASE_PATH || '',

  // Asset prefix for static assets
  assetPrefix: process.env.NEXT_PUBLIC_BASE_PATH || '',

  // Standalone output for Docker deployment
  output: 'standalone',

  // Workspace packages shipped as TypeScript source
  transpilePackages: ['@manfaa/ui', '@manfaa/api-client'],
};

export default nextConfig;
