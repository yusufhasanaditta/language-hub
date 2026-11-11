// Minimal shell for the login / sign-up screens: logo and one centred card.
import { T } from '@/lib/i18n';
import { Flash } from './ui';
import AuthScripts from './AuthScripts';

export function AuthShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="lh-auth-body">
      <Flash />
      <main className="lh-auth">
        <a href="/" className="lh-brand lh-auth-brand">
          <img src="/image/language-hub-logo.png" alt="" width={40} height={40} />
          <span className="brand-name">Language Hub</span>
        </a>
        <div className="lh-auth-card">{children}</div>
        <div className="lh-auth-tools">
          <button type="button" className="lh-toggle" data-lh-lang aria-label="বাংলা / English">
            <span className="lh-toggle-txt"><T bn="English" en="বাংলা" /></span>
          </button>
          <span aria-hidden="true">·</span>
          <button type="button" className="lh-toggle" data-lh-theme aria-label="Dark mode">
            <span className="material-icons-round lh-ico-dark">dark_mode</span>
            <span className="material-icons-round lh-ico-light">light_mode</span>
          </button>
        </div>
      </main>
      <AuthScripts />
    </div>
  );
}
