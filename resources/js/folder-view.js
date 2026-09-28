// Explorador de carpetas (Alpine component "explorer"): inicio, vista de carpeta, selección múltiple,
// menú contextual, arrastrar y soltar, vista previa, detalles, ZIP y navegación sin recargar la página.

import { get, post, patch, del, url, appConfig, iconsUrl } from './api.js';
import { bytes, date, dateTime, relative, plural, kindIcon, kindColor } from './format.js';
import { collectDropped, collectInput } from './upload-queue.js';

const collator = new Intl.Collator('es', { numeric: true, sensitivity: 'base' });
const LAYOUT_KEY = 'fs.layout';
const TEXT_PREVIEW_BYTES = 512 * 1024;

function toast(type, message, action) {
  window.Alpine.store('toasts').push(type, message, action);
}

export function explorer() {
  return {
    mode: 'app',
    token: null,
    icons: iconsUrl,
    canCreateRoot: true,

    view: 'home',
    home: { mine: [], shared: [] },
    homeFilter: '',
    data: null,
    loading: false,
    refreshTimer: null,

    sortKey: 'name',
    sortDir: 'asc',
    layout: 'list',
    selFiles: [],
    selFolders: [],
    dragDepth: 0,
    menu: null,
    downloading: {},
    highlight: null,

    modal: { newFolder: false, rename: false, confirm: false, details: false, preview: false, zip: false },
    form: { name: '', description: '', busy: false, error: '', isRoot: false },
    renameTarget: null,
    confirmState: { title: '', message: '', label: 'Eliminar', busy: false, action: null },
    details: { loading: false, data: null, error: '' },
    preview: { loading: false, info: null, text: '', truncated: false, error: '', file: null },
    zip: { loading: false, info: null, error: '', fileUuids: [], folderUuids: [], all: true },

    // helpers de formato para las plantillas
    bytes, date, dateTime, relative, plural, kindIcon, kindColor,

    init() {
      const initial = JSON.parse(document.getElementById('explorer-initial').textContent || '{}');
      this.mode = initial.mode || 'app';
      this.token = initial.token || null;
      try {
        this.layout = localStorage.getItem(LAYOUT_KEY) || 'list';
      } catch {
        this.layout = 'list';
      }
      if (initial.view === 'home') {
        this.view = 'home';
        this.home = initial.home;
      } else {
        this.view = 'folder';
        this.data = initial.folder;
      }
      this.highlight = new URLSearchParams(location.search).get('file');
      if (this.highlight) this.$nextTick(() => this.scrollToHighlight());
      history.replaceState(this.historyState(), '', location.href);
      this.updateTitle();

      window.addEventListener('popstate', (e) => {
        const state = e.state;
        if (state && state.explorer) this.navigate(state.uuid, { push: false });
      });
      window.addEventListener('explorer:navigate', (e) => this.navigate(e.detail.uuid, { highlight: e.detail.highlight || null }));
      window.addEventListener('uploads:folder-changed', (e) => {
        const d = e.detail || {};
        if (this.view === 'home' || (this.data && (d.folderUuid === this.data.folder.uuid || d.targetUuid === this.data.folder.uuid))) {
          this.scheduleRefresh();
        }
      });
      window.addEventListener('explorer:refresh', () => this.scheduleRefresh(50));
      window.addEventListener('explorer:left-folder', () => this.navigate(null));
      document.addEventListener('click', (e) => {
        const link = e.target.closest('a[data-spa-home]');
        if (link && this.mode === 'app' && !e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0) {
          e.preventDefault();
          this.navigate(null);
        }
      });
      window.__explorer = true;
    },

    // ---------------------------------------------------------------- Navegación

    historyState() {
      return { explorer: true, uuid: this.view === 'home' ? null : this.data.folder.uuid };
    },

    folderHref(uuid) {
      if (this.mode === 'public') return url(`/s/${this.token}?folder=${uuid}`);
      return url(`/folders/${uuid}`);
    },

    homeHref() {
      return this.mode === 'public' ? url(`/s/${this.token}`) : url('/');
    },

    contentsPath(uuid) {
      if (this.mode === 'public') return uuid ? `/s/${this.token}/api/folders/${uuid}` : `/s/${this.token}/api/folder`;
      return `/api/folders/${uuid}`;
    },

    async navigate(uuid, { push = true, highlight = null, silent = false } = {}) {
      this.menu = null;
      if (!silent) {
        this.clearSelection();
        this.loading = true;
      }
      try {
        if (!uuid && this.mode === 'app') {
          this.home = await get('/api/folders');
          this.view = 'home';
          this.data = null;
        } else {
          this.data = await get(this.contentsPath(uuid));
          this.view = 'folder';
        }
        if (!silent) {
          this.highlight = highlight;
          const target = this.view === 'home' ? this.homeHref() : this.folderHref(this.data.folder.uuid);
          const href = highlight ? `${target}${target.includes('?') ? '&' : '?'}file=${highlight}` : target;
          if (push) history.pushState(this.historyState(), '', href);
          window.scrollTo({ top: 0 });
          if (highlight) this.$nextTick(() => this.scrollToHighlight());
        } else {
          // Quitar de la selección lo que ya no existe.
          const files = new Set(this.data ? this.data.files.map((f) => f.uuid) : []);
          const folders = new Set(this.data ? this.data.folders.map((f) => f.uuid) : []);
          this.selFiles = this.selFiles.filter((u) => files.has(u));
          this.selFolders = this.selFolders.filter((u) => folders.has(u));
        }
        this.updateTitle();
      } catch (error) {
        toast('error', error.message);
        if (error.status === 404 && this.view === 'folder' && !silent && this.mode === 'app') {
          this.navigate(null);
        }
      } finally {
        this.loading = false;
      }
    },

    refresh() {
      return this.navigate(this.view === 'home' ? null : this.data.folder.uuid, { push: false, silent: true });
    },

    scheduleRefresh(delay = 600) {
      clearTimeout(this.refreshTimer);
      this.refreshTimer = setTimeout(() => this.refresh(), delay);
    },

    updateTitle() {
      const name = this.view === 'home' ? 'Mis carpetas' : this.data.folder.name;
      document.title = `${name} · ${appConfig.appName || ''}`;
    },

    scrollToHighlight() {
      const el = document.querySelector(`[data-file="${this.highlight}"]`);
      if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
      setTimeout(() => { this.highlight = null; }, 4000);
    },

    // ---------------------------------------------------------------- Inicio

    matches(folder) {
      const q = this.homeFilter.trim().toLowerCase();
      if (!q) return true;
      return folder.name.toLowerCase().includes(q) || (folder.ownerName || '').toLowerCase().includes(q) || (folder.ownerEntity || '').toLowerCase().includes(q);
    },
    get mineFiltered() {
      return this.home.mine.filter((f) => this.matches(f));
    },
    get sharedFiltered() {
      return this.home.shared.filter((f) => this.matches(f));
    },

    // ---------------------------------------------------------------- Listado y orden

    get caps() {
      return (this.data && this.data.capabilities) || {};
    },

    compare(a, b, key) {
      let r;
      if (key === 'size') r = (a.size || 0) - (b.size || 0);
      else if (key === 'date') r = String(a.updatedAt || a.createdAt || '').localeCompare(String(b.updatedAt || b.createdAt || ''));
      else if (key === 'owner') r = collator.compare(a.uploadedBy || a.ownerName || '', b.uploadedBy || b.ownerName || '');
      else r = collator.compare(a.name, b.name);
      if (r === 0 && key !== 'name') r = collator.compare(a.name, b.name);
      return this.sortDir === 'asc' ? r : -r;
    },
    get folders() {
      if (!this.data) return [];
      return [...this.data.folders].sort((a, b) => this.compare(a, b, this.sortKey === 'owner' ? 'name' : this.sortKey));
    },
    get files() {
      if (!this.data) return [];
      return [...this.data.files].sort((a, b) => this.compare(a, b, this.sortKey));
    },
    get isEmpty() {
      return this.data && !this.data.folders.length && !this.data.files.length;
    },
    get totalSize() {
      if (!this.data) return 0;
      return this.data.files.reduce((s, f) => s + f.size, 0) + this.data.folders.reduce((s, f) => s + (f.size || 0), 0);
    },
    sortBy(key) {
      if (this.sortKey === key) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
      else {
        this.sortKey = key;
        this.sortDir = key === 'date' || key === 'size' ? 'desc' : 'asc';
      }
    },
    sortIcon(key) {
      if (this.sortKey !== key) return '';
      return this.sortDir === 'asc' ? 'chevron-up' : 'chevron-down';
    },
    ariaSort(key) {
      if (this.sortKey !== key) return 'none';
      return this.sortDir === 'asc' ? 'ascending' : 'descending';
    },
    setLayout(layout) {
      this.layout = layout;
      try {
        localStorage.setItem(LAYOUT_KEY, layout);
      } catch {
        /* ignorar */
      }
    },

    // ---------------------------------------------------------------- Selección

    isSelected(type, uuid) {
      return (type === 'file' ? this.selFiles : this.selFolders).includes(uuid);
    },
    toggle(type, uuid) {
      const key = type === 'file' ? 'selFiles' : 'selFolders';
      this[key] = this[key].includes(uuid) ? this[key].filter((u) => u !== uuid) : [...this[key], uuid];
    },
    get selectionCount() {
      return this.selFiles.length + this.selFolders.length;
    },
    get selectionSize() {
      if (!this.data) return 0;
      const files = this.data.files.filter((f) => this.selFiles.includes(f.uuid)).reduce((s, f) => s + f.size, 0);
      const folders = this.data.folders.filter((f) => this.selFolders.includes(f.uuid)).reduce((s, f) => s + (f.size || 0), 0);
      return files + folders;
    },
    get allSelected() {
      return this.data && this.selectionCount > 0 && this.selectionCount === this.data.files.length + this.data.folders.length;
    },
    toggleAll() {
      if (this.allSelected) this.clearSelection();
      else {
        this.selFiles = this.data.files.map((f) => f.uuid);
        this.selFolders = this.data.folders.map((f) => f.uuid);
      }
    },
    clearSelection() {
      this.selFiles = [];
      this.selFolders = [];
    },

    // ---------------------------------------------------------------- Menú contextual

    openMenu(type, item, event) {
      event.preventDefault();
      event.stopPropagation();
      let x;
      let y;
      if (event.type === 'contextmenu') {
        x = event.clientX;
        y = event.clientY;
      } else {
        const rect = event.currentTarget.getBoundingClientRect();
        x = rect.right;
        y = rect.bottom + 4;
      }
      const width = 224;
      const height = type === 'file' ? 230 : 150;
      x = Math.max(8, Math.min(x - (event.type === 'contextmenu' ? 0 : width), window.innerWidth - width - 8));
      y = Math.min(y, window.innerHeight - height - 8);
      this.menu = { type, item, x, y };
    },
    closeMenu() {
      this.menu = null;
    },

    // ---------------------------------------------------------------- Archivos

    downloadHref(file) {
      if (this.mode === 'public') return url(`/s/${this.token}/files/${file.uuid}/download`);
      return url(`/api/files/${file.uuid}/download`);
    },

    download(file) {
      this.menu = null;
      this.downloading = { ...this.downloading, [file.uuid]: true };
      const a = document.createElement('a');
      a.href = this.downloadHref(file);
      a.rel = 'noopener';
      document.body.appendChild(a);
      a.click();
      a.remove();
      toast('info', `Descargando “${file.name}”… El progreso aparece en las descargas de tu navegador.`);
      setTimeout(() => {
        const copy = { ...this.downloading };
        delete copy[file.uuid];
        this.downloading = copy;
      }, 3000);
    },

    // ---------------------------------------------------------------- Vista previa

    get previewables() {
      return this.files.filter((f) => f.previewable);
    },

    async openPreview(file) {
      this.menu = null;
      if (!file.previewable) {
        this.download(file);
        return;
      }
      this.modal.preview = true;
      this.preview = { loading: true, info: null, text: '', truncated: false, error: '', file };
      try {
        const path = this.mode === 'public' ? `/s/${this.token}/files/${file.uuid}/preview` : `/api/files/${file.uuid}/preview`;
        const info = await get(path);
        if (this.preview.file !== file) return;
        if (info.kind === 'text') {
          const res = await fetch(info.url, { headers: { Range: `bytes=0-${TEXT_PREVIEW_BYTES - 1}` } });
          if (!res.ok && res.status !== 206) throw new Error('No se pudo leer el archivo.');
          const buffer = await res.arrayBuffer();
          this.preview.text = new TextDecoder('utf-8').decode(buffer);
          this.preview.truncated = file.size > TEXT_PREVIEW_BYTES;
        }
        this.preview.info = info;
      } catch (error) {
        this.preview.error = error.message || 'No se pudo abrir la vista previa.';
      } finally {
        this.preview.loading = false;
      }
    },

    previewStep(delta) {
      const list = this.previewables;
      if (!this.preview.file || list.length < 2) return;
      const index = list.findIndex((f) => f.uuid === this.preview.file.uuid);
      const next = list[(index + delta + list.length) % list.length];
      this.openPreview(next);
    },

    closePreview() {
      this.modal.preview = false;
      this.preview = { loading: false, info: null, text: '', truncated: false, error: '', file: null };
    },

    // ---------------------------------------------------------------- Detalles

    async openDetails(file) {
      this.menu = null;
      this.modal.details = true;
      this.details = { loading: true, data: null, error: '' };
      try {
        this.details.data = await get(`/api/files/${file.uuid}`);
      } catch (error) {
        this.details.error = error.message;
      } finally {
        this.details.loading = false;
      }
    },

    versionHref(uuid) {
      return url(`/api/files/${uuid}/download`);
    },

    // ---------------------------------------------------------------- Crear / renombrar / eliminar

    openNewFolder(isRoot = false) {
      this.form = { name: '', description: '', busy: false, error: '', isRoot };
      this.modal.newFolder = true;
      this.$nextTick(() => this.$refs.newFolderName && this.$refs.newFolderName.focus());
    },

    async submitNewFolder() {
      if (!this.form.name.trim()) {
        this.form.error = 'Escribe un nombre para la carpeta.';
        return;
      }
      this.form.busy = true;
      this.form.error = '';
      try {
        if (this.form.isRoot) {
          const folder = await post('/api/folders', { name: this.form.name, description: this.form.description });
          this.modal.newFolder = false;
          toast('success', `Carpeta “${folder.name}” creada. Ya puedes subir archivos.`);
          this.navigate(folder.uuid);
        } else {
          const folder = await post(`/api/folders/${this.data.folder.uuid}/folders`, { name: this.form.name });
          this.modal.newFolder = false;
          toast('success', `Subcarpeta “${folder.name}” creada.`);
          this.refresh();
        }
      } catch (error) {
        this.form.error = error.message;
      } finally {
        this.form.busy = false;
      }
    },

    openRename(type, item) {
      this.menu = null;
      this.renameTarget = { type, item };
      this.form = { name: item.name, description: '', busy: false, error: '', isRoot: false };
      this.modal.rename = true;
      this.$nextTick(() => {
        const input = this.$refs.renameInput;
        if (!input) return;
        input.focus();
        const dot = type === 'file' ? item.name.lastIndexOf('.') : -1;
        input.setSelectionRange(0, dot > 0 ? dot : item.name.length);
      });
    },

    async submitRename() {
      const { type, item } = this.renameTarget;
      const name = this.form.name.trim();
      if (!name) {
        this.form.error = 'El nombre no puede quedar vacío.';
        return;
      }
      if (name === item.name) {
        this.modal.rename = false;
        return;
      }
      this.form.busy = true;
      this.form.error = '';
      try {
        if (type === 'file') await patch(`/api/files/${item.uuid}`, { name });
        else await patch(`/api/folders/${item.uuid}`, { name });
        this.modal.rename = false;
        toast('success', 'Nombre actualizado.');
        this.refresh();
      } catch (error) {
        this.form.error = error.message;
      } finally {
        this.form.busy = false;
      }
    },

    confirm(title, message, label, action) {
      this.menu = null;
      this.confirmState = { title, message, label, busy: false, action };
      this.modal.confirm = true;
    },

    async runConfirm() {
      this.confirmState.busy = true;
      try {
        await this.confirmState.action();
        this.modal.confirm = false;
      } catch (error) {
        toast('error', error.message);
      } finally {
        this.confirmState.busy = false;
      }
    },

    askDeleteFile(file) {
      this.confirm('¿Eliminar este archivo?', `“${file.name}” dejará de estar disponible para todas las personas con acceso a la carpeta.`, 'Eliminar archivo', async () => {
        await del(`/api/files/${file.uuid}`);
        toast('success', `“${file.name}” eliminado.`);
        this.refresh();
      });
    },

    askDeleteFolder(folder) {
      this.confirm('¿Eliminar esta carpeta?', `Se eliminará “${folder.name}” con todo su contenido (${plural(folder.filesCount || 0, 'archivo', 'archivos')}).`, 'Eliminar carpeta', async () => {
        await del(`/api/folders/${folder.uuid}`);
        toast('success', `Carpeta “${folder.name}” eliminada.`);
        this.refresh();
      });
    },

    askDeleteSelection() {
      const n = this.selectionCount;
      this.confirm(`¿Eliminar ${plural(n, 'elemento', 'elementos')}?`, 'Los archivos y carpetas seleccionados dejarán de estar disponibles para todas las personas con acceso.', 'Eliminar', async () => {
        const result = await post(`/api/folders/${this.data.folder.uuid}/delete-items`, { fileUuids: this.selFiles, folderUuids: this.selFolders });
        const total = result.files + result.folders;
        toast('success', `${plural(total, 'elemento eliminado', 'elementos eliminados')}.`);
        this.clearSelection();
        this.refresh();
      });
    },

    askDeleteCurrent() {
      const folder = this.data.folder;
      const isRoot = folder.isRoot;
      const parent = this.data.breadcrumb.length > 1 ? this.data.breadcrumb[this.data.breadcrumb.length - 2].uuid : null;
      this.confirm(
        isRoot ? '¿Eliminar la carpeta completa?' : '¿Eliminar esta subcarpeta?',
        isRoot
          ? `Se eliminará “${folder.name}” con todo su contenido y nadie podrá volver a acceder. Esta acción no se puede deshacer desde la aplicación.`
          : `Se eliminará “${folder.name}” con todo su contenido.`,
        'Eliminar carpeta',
        async () => {
          await del(`/api/folders/${folder.uuid}`);
          toast('success', `Carpeta “${folder.name}” eliminada.`);
          this.navigate(isRoot ? null : parent);
        },
      );
    },

    // ---------------------------------------------------------------- Compartir

    openShare() {
      window.dispatchEvent(new CustomEvent('share:open', {
        detail: { uuid: this.data.folder.uuid, name: this.data.folder.name, rootName: this.data.folder.rootName, isRoot: this.data.folder.isRoot, caps: this.caps },
      }));
    },

    // ---------------------------------------------------------------- Subidas

    pickFiles() {
      this.$refs.fileInput.click();
    },
    pickFolder() {
      this.$refs.folderInput.click();
    },
    onPicked(event) {
      const files = event.target.files;
      if (files && files.length) {
        this.$store.uploads.add(collectInput(files), this.data.folder.uuid, this.data.folder.name);
      }
      event.target.value = '';
    },
    isFileDrag(event) {
      return event.dataTransfer && Array.from(event.dataTransfer.types || []).includes('Files');
    },
    onDragEnter(event) {
      if (!this.isFileDrag(event)) return;
      event.preventDefault();
      this.dragDepth++;
    },
    onDragOver(event) {
      if (!this.isFileDrag(event)) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = this.view === 'folder' && this.caps.upload ? 'copy' : 'none';
    },
    onDragLeave(event) {
      if (!this.isFileDrag(event)) return;
      this.dragDepth = Math.max(0, this.dragDepth - 1);
    },
    onDrop(event) {
      if (!this.isFileDrag(event)) return;
      event.preventDefault();
      this.dragDepth = 0;
      if (this.view !== 'folder') {
        toast('info', 'Abre o crea una carpeta para subir archivos en ella.');
        return;
      }
      if (!this.caps.upload) {
        toast('error', 'Tienes acceso de solo lectura a esta carpeta: no puedes subir archivos.');
        return;
      }
      const folder = this.data.folder;
      collectDropped(event.dataTransfer)
        .then((collected) => this.$store.uploads.add(collected, folder.uuid, folder.name))
        .catch(() => toast('error', 'No se pudieron leer los archivos soltados. Intenta con el botón "Subir archivos".'));
    },
    get dragging() {
      return this.dragDepth > 0;
    },

    // ---------------------------------------------------------------- ZIP

    async openZip(selectionOnly) {
      this.zip = {
        loading: true,
        info: null,
        error: '',
        fileUuids: selectionOnly ? [...this.selFiles] : [],
        folderUuids: selectionOnly ? [...this.selFolders] : [],
        all: !selectionOnly,
      };
      this.modal.zip = true;
      try {
        const path = this.mode === 'public' ? `/s/${this.token}/zip-info` : `/api/folders/${this.data.folder.uuid}/zip-info`;
        this.zip.info = await post(path, { folderUuid: this.data.folder.uuid, fileUuids: this.zip.fileUuids, folderUuids: this.zip.folderUuids });
      } catch (error) {
        this.zip.error = error.message;
      } finally {
        this.zip.loading = false;
      }
    },

    startZip() {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = this.mode === 'public' ? url(`/s/${this.token}/download-zip`) : url(`/api/folders/${this.data.folder.uuid}/download-zip`);
      form.target = 'download-frame';
      const add = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      };
      add('_csrf', appConfig.csrf);
      add('folderUuid', this.data.folder.uuid);
      this.zip.fileUuids.forEach((u) => add('fileUuids[]', u));
      this.zip.folderUuids.forEach((u) => add('folderUuids[]', u));
      document.body.appendChild(form);
      form.submit();
      form.remove();
      this.modal.zip = false;
      toast('info', `Preparando “${this.zip.info.name}”. La descarga empezará en unos segundos; verás el progreso en tu navegador.`);
    },

    // ---------------------------------------------------------------- Teclado

    onKeydown(event) {
      if (event.key === 'Escape') {
        if (this.menu) this.menu = null;
        else if (this.modal.preview) this.closePreview();
        else if (this.modal.details) this.modal.details = false;
        else if (this.modal.zip) this.modal.zip = false;
        else if (this.modal.confirm && !this.confirmState.busy) this.modal.confirm = false;
        else if (this.modal.rename) this.modal.rename = false;
        else if (this.modal.newFolder) this.modal.newFolder = false;
        else if (this.selectionCount) this.clearSelection();
      } else if (this.modal.preview && event.key === 'ArrowRight') {
        this.previewStep(1);
      } else if (this.modal.preview && event.key === 'ArrowLeft') {
        this.previewStep(-1);
      }
    },
  };
}
