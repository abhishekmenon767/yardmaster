import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// The dashboard ships compiled inside the package: an application installs it
// with composer and gets a working UI, with no npm step and no CDN. Assets are
// served from routes rather than inlined into the page, so a strict
// Content-Security-Policy needs no unsafe-inline exception.
export default defineConfig({
  plugins: [vue()],
  // Library builds do not substitute these the way application builds do, so
  // without them Vue reaches for `process` in the browser and the app never
  // mounts. Options API is off because this dashboard uses none of it.
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
    __VUE_OPTIONS_API__: 'false',
    __VUE_PROD_DEVTOOLS__: 'false',
    __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    lib: {
      entry: 'resources/js/app.js',
      name: 'Yardmaster',
      formats: ['iife'],
      fileName: () => 'yardmaster.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: 'yardmaster.[ext]',
      },
    },
  },
})
