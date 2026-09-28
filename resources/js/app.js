// Punto de entrada: registra stores y componentes de Alpine y arranca la aplicación.

import Alpine from './vendor/alpine.esm.js';
import { appConfig, iconsUrl, url } from './api.js';
import { createUploadStore } from './upload-queue.js';
import { explorer } from './folder-view.js';
import { shareDialog } from './share-dialog.js';
import { globalSearch } from './search.js';
import { bytes } from './format.js';

let toastId = 1;

Alpine.store('toasts', {
  items: [],
  icons: iconsUrl,
  push(type, message, action = null) {
    const id = toastId++;
    this.items.push({ id, type, message, action });
    if (this.items.length > 5) this.items.shift();
    const ttl = type === 'error' ? 9000 : 5000;
    setTimeout(() => this.dismiss(id), ttl);
    return id;
  },
  dismiss(id) {
    this.items = this.items.filter((t) => t.id !== id);
  },
});

if (appConfig.user) {
  Alpine.store('uploads', createUploadStore());
}

Alpine.data('explorer', explorer);
Alpine.data('shareDialog', shareDialog);
Alpine.data('globalSearch', globalSearch);

// Utilidades globales accesibles desde las plantillas.
Alpine.magic('bytes', () => bytes);

window.Alpine = Alpine;
Alpine.start();

// Mensajes flash del servidor -> toasts (las pantallas de acceso los muestran en línea).
const flashesEl = document.getElementById('app-flashes');
if (flashesEl && !document.body.hasAttribute('data-inline-flashes')) {
  try {
    for (const flash of JSON.parse(flashesEl.textContent || '[]')) {
      Alpine.store('toasts').push(flash.type === 'error' ? 'error' : flash.type === 'success' ? 'success' : 'info', flash.message);
    }
  } catch {
    /* ignorar */
  }
}

window.addEventListener('app:session-expired', () => {
  Alpine.store('toasts').push('error', 'Tu sesión expiró. Vuelve a iniciar sesión para continuar.', {
    label: 'Iniciar sesión',
    run: () => { window.location.href = url(`/login?next=${encodeURIComponent(window.location.pathname.replace(appConfig.basePath || '', '') || '/')}`); },
  });
});
