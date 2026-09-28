// Búsqueda global en la cabecera (carpetas y archivos accesibles).

import { get, url, iconsUrl } from './api.js';
import { bytes, kindIcon, kindColor } from './format.js';

export function globalSearch() {
  return {
    q: '',
    open: false,
    loading: false,
    results: { folders: [], files: [] },
    active: -1,
    icons: iconsUrl,
    bytes, kindIcon, kindColor,
    controller: null,

    get total() {
      return this.results.folders.length + this.results.files.length;
    },

    async search() {
      const q = this.q.trim();
      if (q.length < 2) {
        this.open = false;
        this.results = { folders: [], files: [] };
        return;
      }
      if (this.controller) this.controller.abort();
      this.controller = new AbortController();
      this.loading = true;
      this.open = true;
      try {
        this.results = await get(`/api/search?q=${encodeURIComponent(q)}`, { signal: this.controller.signal });
        this.active = -1;
      } catch (error) {
        if (error.name !== 'AbortError') this.results = { folders: [], files: [] };
      } finally {
        this.loading = false;
      }
    },

    close() {
      this.open = false;
    },

    move(delta) {
      if (!this.total) return;
      this.open = true;
      this.active = (this.active + delta + this.total) % this.total;
    },

    choose() {
      if (this.active < 0) return;
      if (this.active < this.results.folders.length) this.goFolder(this.results.folders[this.active].uuid);
      else this.goFile(this.results.files[this.active - this.results.folders.length]);
    },

    goFolder(uuid) {
      this.close();
      if (window.__explorer) window.dispatchEvent(new CustomEvent('explorer:navigate', { detail: { uuid } }));
      else window.location.href = url(`/folders/${uuid}`);
    },

    goFile(file) {
      this.close();
      if (window.__explorer) window.dispatchEvent(new CustomEvent('explorer:navigate', { detail: { uuid: file.folderUuid, highlight: file.uuid } }));
      else window.location.href = url(`/folders/${file.folderUuid}?file=${file.uuid}`);
    },
  };
}
