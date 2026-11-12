import type { NextConfig } from 'next';

const securityHeaders = [
  { key: 'X-Content-Type-Options', value: 'nosniff' },
  { key: 'X-Frame-Options', value: 'SAMEORIGIN' },
  { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
  { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=()' },
  { key: 'Strict-Transport-Security', value: 'max-age=31536000; includeSubDomains' },
];

const config: NextConfig = {
  poweredByHeader: false,
  // `npm run build:cpanel` builds a self-contained server for cPanel / EBNhost (Vercel ignores this).
  ...(process.env.BUILD_STANDALONE ? { output: 'standalone' as const } : {}),
  // Static page copy is read from disk at request time.
  outputFileTracingIncludes: { '/*': ['./content/**/*'] },
  async headers() {
    return [
      { source: '/:path*', headers: securityHeaders },
      { source: '/sw.js', headers: [{ key: 'Cache-Control', value: 'no-cache' }] },
    ];
  },
  // Old PHP links (bookmarks, shared links, search results) keep working.
  async redirects() {
    return [
      { source: '/index.php', destination: '/', permanent: true },
      { source: '/admin/index.php', destination: '/admin', permanent: true },
      { source: '/teacher/dashboard.php', destination: '/teacher', permanent: true },
      { source: '/teacher/dashboard', destination: '/teacher', permanent: true },
      { source: '/register', destination: '/login', permanent: true },
      { source: '/login-otp.php', destination: '/login', permanent: true },
      {
        source: '/course.php', has: [{ type: 'query', key: 'slug', value: '(?<slug>.+)' }],
        destination: '/course/:slug', permanent: true,
      },
      {
        source: '/invoice.php', has: [{ type: 'query', key: 'order', value: '(?<order>\\d+)' }],
        destination: '/invoice/:order', permanent: true,
      },
      { source: '/teacher-profile.php', destination: '/teachers', permanent: true },
      { source: '/:path((?!api/).+)\\.php', destination: '/:path', permanent: true },
    ];
  },
  // The original front-end scripts call /api/*.php endpoints.
  async rewrites() {
    return [{ source: '/api/:name.php', destination: '/api/:name' }];
  },
};

export default config;
