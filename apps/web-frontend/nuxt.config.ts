export default defineNuxtConfig({
  compatibilityDate: '2026-11-07',
  ssr: false,
  devtools: { enabled: false },
  modules: ['vuetify-nuxt-module'],
  css: [
    '@mdi/font/css/materialdesignicons.css',
    '~/assets/css/main.css',
  ],
  vuetify: {
    vuetifyOptions: {
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
