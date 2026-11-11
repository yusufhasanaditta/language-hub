// Per-language presentation data, keyed by category slug.
export type LangMeta = { glyph: string; hello: string; native: string; exam: string; cssVar: string };

const LANGS: Record<string, LangMeta> = {
  chinese:  { glyph: '中', hello: '你好',       native: '中文',    exam: 'HSK',   cssVar: '--lang-zh' },
  japanese: { glyph: 'あ', hello: 'こんにちは', native: '日本語',  exam: 'JLPT',  cssVar: '--lang-ja' },
  korean:   { glyph: '한', hello: '안녕하세요', native: '한국어',  exam: 'TOPIK', cssVar: '--lang-ko' },
  english:  { glyph: 'A',  hello: 'Hello',      native: 'English', exam: 'IELTS', cssVar: '--lang-en' },
};

export function lang(slug: string | null | undefined): LangMeta {
  return LANGS[slug ?? ''] ?? { glyph: 'L', hello: 'Hi', native: '', exam: '', cssVar: '--c-primary' };
}

/** style={langStyle(slug)} sets the --lang colour for a category. */
export const langStyle = (slug: string | null | undefined) =>
  ({ '--lang': `var(${lang(slug).cssVar})` }) as React.CSSProperties;
