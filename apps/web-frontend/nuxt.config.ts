export default defineNuxtConfig({
  compatibilityDate: '2026-11-07',
  ssr: false,
  app: {
    head: {
      title: 'AutoFlow | Mobility Platform',
      meta: [
        { name: 'theme-color', content: '#101820' },
        { name: 'description', content: 'AutoFlow mobility operations, vehicle catalogue and offer management.' },
      ],
    },
  },
  devtools: { enabled: false },
  experimental: {
    // Nuxt's generated Vue Router Volar plugin is not resolvable in the
    // current vue-router package. Keep file-based routing, disable only
    // generated typed-route integration until the upstream package is compatible.
    typedPages: false,
  },
  modules: ['vuetify-nuxt-module'],
  css: [
    '@mdi/font/css/materialdesignicons.css',
    '~/assets/css/main.css',
  ],
  vuetify: {
    moduleOptions: {
      // Nuxt 4.5+ provides its own useLayout composable.
      // Keep Nuxt's composable and expose Vuetify's as useVLayout.
      prefixComposables: ['useLayout'],
    },
    vuetifyOptions: {
      icons: { defaultSet: 'mdi' },
      theme: {
        defaultTheme: 'dark',
        themes: {
          dark: {
            colors: {
              background: '#101820',
              surface: '#17232d',
              primary: '#c8f169',
              secondary: '#82939b',
              error: '#ef8d91',
            },
          },
        },
      },
    },
  },
  typescript: {
    strict: true,
    typeCheck: true,
  },
  runtimeConfig: {
    public: {
      appName: 'AutoFlow',
    },
  },
});
