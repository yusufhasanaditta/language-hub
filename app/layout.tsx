import type { Metadata, Viewport } from 'next';

export const metadata: Metadata = {
  title: { default: 'Language Hub — নতুন ভাষা, নতুন সুযোগ', template: '%s — Language Hub' },
  description: 'বাংলায় চাইনিজ, জাপানিজ, কোরিয়ান ও ইংলিশ শিখুন — লাইভ ব্যাচ (সর্বোচ্চ ২০ জন) ও রেকর্ডেড কোর্স।',
  icons: { icon: '/image/language-hub-logo-192.png' },
  openGraph: { type: 'website', siteName: 'Language Hub', images: ['/image/language-hub-logo.png'] },
};

export const viewport: Viewport = { themeColor: '#5B21B6', width: 'device-width', initialScale: 1 };

// Apply the saved theme + language before first paint (no flash).
const PREPAINT = `(function(){var d=document.documentElement,t,l;d.classList.add('lh-js');
try{t=localStorage.getItem('lh_theme');l=localStorage.getItem('lh_lang')}catch(e){}
var dark=t?t==='dark':(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches);
d.setAttribute('data-theme',dark?'dark':'light');
if(l==='en'||l==='bn'){d.setAttribute('data-lang',l);d.lang=l}})();`;

const FONTS = 'https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@400;500;600;700;800&family=Hind+Siliguri:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap';

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="bn" data-lang="bn" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: PREPAINT }} />
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
        <link rel="stylesheet" href={FONTS} />
        <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons+Round&display=swap" />
        <link rel="stylesheet" href="/assets/css/main.css?v=2.1.0" />
        <link rel="stylesheet" href="/assets/css/app.css?v=2.0.0" />
        <link rel="stylesheet" href="/assets/css/lh.css?v=2.0.0" />
        <link rel="stylesheet" href="/assets/css/lh2.css?v=2.4.0" />
        <link rel="stylesheet" href="/assets/css/panel.css?v=2.4.0" />
      </head>
      <body suppressHydrationWarning>{children}</body>
    </html>
  );
}
