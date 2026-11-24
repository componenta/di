import { defineConfig } from 'vitepress'

const enGuide = [
  { text: 'Getting started', link: '/guide/getting-started' },
  { text: 'Dependency definitions', link: '/guide/definitions' },
  { text: 'Container API', link: '/guide/container' },
  { text: 'Lazy objects and proxies', link: '/guide/lazy-objects' },
  { text: 'Attributes and request mapping', link: '/guide/attributes' },
  { text: 'Extension points', link: '/guide/extensions' },
  { text: 'Runtime and exceptions', link: '/guide/runtime' }
]

const ruGuide = [
  { text: 'Начало работы', link: '/ru/guide/getting-started' },
  { text: 'Определения зависимостей', link: '/ru/guide/definitions' },
  { text: 'API контейнера', link: '/ru/guide/container' },
  { text: 'Ленивые объекты и прокси', link: '/ru/guide/lazy-objects' },
  { text: 'Атрибуты и HTTP-запросы', link: '/ru/guide/attributes' },
  { text: 'Расширение контейнера', link: '/ru/guide/extensions' },
  { text: 'Поведение и исключения', link: '/ru/guide/runtime' }
]

export default defineConfig({
  title: 'Componenta DI',
  description: 'Dependency injection for PHP',
  base: process.env.DOCS_BASE ?? '/di/',
  cleanUrls: true,
  srcExclude: ['plans/**', 'releases/**'],
  rewrites: {
    'migrations/di6-definitions.ru.md': 'ru/migrations/di6-definitions.md',
    'migrations/di6-callbacks.ru.md': 'ru/migrations/di6-callbacks.md'
  },
  themeConfig: {
    search: { provider: 'local' },
    socialLinks: [{ icon: 'github', link: 'https://github.com/componenta/di' }]
  },
  locales: {
    root: {
      label: 'English',
      lang: 'en-US',
      themeConfig: {
        nav: [
          { text: 'Guide', link: '/guide/getting-started' },
          { text: 'Migration', link: '/migrations/di6-definitions' }
        ],
        sidebar: [
          { text: 'Guide', items: enGuide },
          {
            text: 'DI 6 migration',
            items: [
              { text: 'Definitions', link: '/migrations/di6-definitions' },
              { text: 'Callbacks and unions', link: '/migrations/di6-callbacks' }
            ]
          }
        ]
      }
    },
    ru: {
      label: 'Русский',
      lang: 'ru-RU',
      themeConfig: {
        nav: [
          { text: 'Руководство', link: '/ru/guide/getting-started' },
          { text: 'Миграция', link: '/ru/migrations/di6-definitions' }
        ],
        sidebar: [
          { text: 'Руководство', items: ruGuide },
          {
            text: 'Миграция на DI 6',
            items: [
              { text: 'Определения', link: '/ru/migrations/di6-definitions' },
              { text: 'Callbacks и unions', link: '/ru/migrations/di6-callbacks' }
            ]
          }
        ]
      }
    }
  }
})
