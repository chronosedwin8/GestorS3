// Cola global de subidas (Alpine store "uploads"): concurrencia, progreso por archivo y global,
// velocidad, tiempo restante, pausa/reanudación/cancelación/reintento y conflictos de nombre.

import { S3Uploader, PausedError, CancelledError, UploadError, resumeStore } from './s3-uploader.js';
import { ApiError, appConfig, post, del, iconsUrl } from './api.js';
import { bytes, speed, duration, plural, kindOf, kindIcon, kindColor, extension } from './format.js';

export const QUEUE_SETTINGS = {
  concurrency: 3, // archivos simultáneos
};

const IGNORED_FILES = new Set(['.ds_store', 'thumbs.db', 'desktop.ini']);

// Objetos no reactivos (File, uploader) fuera del proxy de Alpine.
const runtime = new Map();
let nextId = 1;

function toast(type, message, action) {
  window.Alpine?.store('toasts')?.push(type, message, action);
}

/**
 * Recorre lo soltado (archivos y carpetas) usando webkitGetAsEntry.
 * IMPORTANTE: llamar sincrónicamente dentro del evento drop.
 */
export function collectDropped(dataTransfer) {
  const entries = [];
  const plainFiles = [];
  for (const item of dataTransfer.items || []) {
    if (item.kind !== 'file') continue;
    const entry = item.webkitGetAsEntry ? item.webkitGetAsEntry() : null;
    if (entry) entries.push(entry);
    else {
      const f = item.getAsFile();
      if (f) plainFiles.push(f);
    }
  }
  if (!entries.length && dataTransfer.files) {
    for (const f of dataTransfer.files) plainFiles.push(f);
  }
  return (async () => {
    const files = plainFiles.map((file) => ({ file, relativePath: '' }));
    const dirs = [];
    const readAll = (reader) => new Promise((resolve, reject) => reader.readEntries(resolve, reject));
    const walk = async (entry, path) => {
      if (entry.isFile) {
        const file = await new Promise((resolve, reject) => entry.file(resolve, reject));
        files.push({ file, relativePath: path ? path + file.name : '' });
      } else if (entry.isDirectory) {
        const dirPath = path + entry.name;
        dirs.push(dirPath);
        const reader = entry.createReader();
        let batch;
        do {
          batch = await readAll(reader);
          for (const child of batch) await walk(child, dirPath + '/');
        } while (batch.length);
      }
    };
    for (const entry of entries) await walk(entry, '');
    return { files, dirs };
  })();
}

/** Archivos elegidos con <input type=file> (con o sin webkitdirectory). */
export function collectInput(fileList) {
  const files = [];
  const dirs = new Set();
  for (const file of fileList) {
    const rel = file.webkitRelativePath || '';
    files.push({ file, relativePath: rel });
    if (rel) {
      const parts = rel.split('/');
      for (let i = 1; i < parts.length; i++) dirs.add(parts.slice(0, i).join('/'));
    }
  }
  return { files, dirs: [...dirs] };
}

export function createUploadStore() {
  return {
    items: [],
    open: false,
    minimized: false,
    conflict: null, // { id, name, folder, existing }
    conflictApplyAll: false,
    conflictDefault: null, // decisión aplicada a todos
    statusText: '',
    icons: iconsUrl,
    batchCounts: {}, // folderUuid -> archivos completados (para notificar)
    autoPaused: [],
    tickTimer: null,

    init() {
      window.addEventListener('beforeunload', (e) => {
        if (this.hasActive) {
          e.preventDefault();
          e.returnValue = '';
        }
      });
      window.addEventListener('offline', () => this.onOffline());
      window.addEventListener('online', () => this.onOnline());
      // Subidas multipart interrumpidas por una recarga: se pueden continuar eligiendo el archivo de nuevo.
      for (const entry of resumeStore.all()) {
        this.items.push(this.makeItem({
          name: entry.name,
          size: entry.size,
          folderUuid: entry.folderUuid,
          relativePath: entry.relativePath,
          status: 'interrupted',
          resumeSession: entry.sessionUuid,
          lastModified: entry.lastModified,
        }));
      }
      if (this.items.length) this.open = true;
    },

    makeItem(data) {
      return {
        id: nextId++,
        name: data.name,
        size: data.size,
        kind: kindOf(data.name),
        folderUuid: data.folderUuid,
        folderName: data.folderName || '',
        relativePath: data.relativePath || '',
        status: data.status || 'queued', // queued|preparing|uploading|retrying|finishing|paused|done|error|canceled|skipped|conflict|interrupted
        loaded: 0,
        speed: 0,
        eta: 0,
        error: '',
        errorRetryable: true,
        onConflict: null,
        resumeSession: data.resumeSession || null,
        lastModified: data.lastModified || 0,
        resultName: '',
        startedAt: 0,
        lastSample: { t: 0, loaded: 0 },
      };
    },

    // ---------------------------------------------------------------- Getters

    get active() {
      return this.items.filter((i) => ['preparing', 'uploading', 'retrying', 'finishing'].includes(i.status));
    },
    get hasActive() {
      return this.items.some((i) => ['queued', 'preparing', 'uploading', 'retrying', 'finishing', 'conflict'].includes(i.status));
    },
    get counts() {
      const c = { total: 0, done: 0, error: 0, active: 0, queued: 0, paused: 0, other: 0 };
      for (const i of this.items) {
        c.total++;
        if (i.status === 'done' || i.status === 'skipped') c.done++;
        else if (i.status === 'error') c.error++;
        else if (i.status === 'queued' || i.status === 'conflict') c.queued++;
        else if (i.status === 'paused' || i.status === 'interrupted') c.paused++;
        else if (i.status === 'canceled') c.other++;
        else c.active++;
      }
      return c;
    },
    get relevant() {
      return this.items.filter((i) => i.status !== 'canceled');
    },
    get totalBytes() {
      return this.relevant.filter((i) => i.status !== 'skipped').reduce((s, i) => s + i.size, 0);
    },
    get loadedBytes() {
      return this.relevant.filter((i) => i.status !== 'skipped').reduce((s, i) => s + Math.min(i.loaded, i.size), 0);
    },
    get percent() {
      const total = this.totalBytes;
      if (!total) return this.counts.total && this.counts.done === this.relevant.length ? 100 : 0;
      return Math.min(100, Math.floor((this.loadedBytes / total) * 100));
    },
    get globalSpeed() {
      return this.active.reduce((s, i) => s + (i.speed || 0), 0);
    },
    get globalEta() {
      const sp = this.globalSpeed;
      if (!sp) return 0;
      const remaining = this.relevant
        .filter((i) => !['done', 'skipped', 'error'].includes(i.status))
        .reduce((s, i) => s + Math.max(0, i.size - i.loaded), 0);
      return remaining / sp;
    },
    get title() {
      const c = this.counts;
      if (this.hasActive) {
        return `Subiendo ${plural(c.active + c.queued, 'archivo', 'archivos')}`;
      }
      if (c.error) return `${plural(c.error, 'archivo', 'archivos')} con error`;
      if (c.paused) return `${plural(c.paused, 'subida pausada', 'subidas pausadas')}`;
      if (c.done) return `${plural(c.done, 'archivo subido', 'archivos subidos')}`;
      return 'Subidas';
    },
    get subtitle() {
      if (!this.hasActive) return '';
      const parts = [`${this.percent}%`, `${bytes(this.loadedBytes)} de ${bytes(this.totalBytes)}`];
      if (this.globalSpeed) parts.push(speed(this.globalSpeed));
      const eta = duration(this.globalEta);
      if (eta) parts.push(`quedan ${eta}`);
      return parts.join(' · ');
    },

    // ---------------------------------------------------------------- Helpers de vista

    bytes, speed, duration, kindIcon, kindColor,
    itemPercent(item) {
      if (!item.size) return item.status === 'done' ? 100 : 0;
      return Math.min(100, Math.floor((item.loaded / item.size) * 100));
    },
    statusLabel(item) {
      switch (item.status) {
        case 'queued': return 'En cola';
        case 'preparing': return 'Preparando…';
        case 'uploading': {
          const parts = [`${this.itemPercent(item)}%`];
          if (item.speed) parts.push(speed(item.speed));
          const eta = duration(item.eta);
          if (eta) parts.push(`quedan ${eta}`);
          return parts.join(' · ');
        }
        case 'retrying': return 'Conexión inestable, reintentando…';
        case 'finishing': return 'Verificando…';
        case 'paused': return `Pausado · ${this.itemPercent(item)}%`;
        case 'done': return item.resultName && item.resultName !== item.name ? `Subido como “${item.resultName}”` : 'Completado';
        case 'skipped': return 'Omitido (ya existía)';
        case 'canceled': return 'Cancelado';
        case 'conflict': return 'Ya existe un archivo con este nombre';
        case 'interrupted': return 'Interrumpido al recargar la página';
        case 'error': return item.error;
        default: return '';
      }
    },

    // ---------------------------------------------------------------- Agregar

    /**
     * @param {{files: {file: File, relativePath: string}[], dirs?: string[]}} collected
     * @param {string} folderUuid
     */
    async add(collected, folderUuid, folderName = '') {
      const limits = appConfig.limits || {};
      const blocked = new Set(limits.blockedExtensions || []);
      const files = collected.files.filter(({ file }) => !IGNORED_FILES.has(file.name.toLowerCase()));

      // Carpetas vacías: crearlas para reproducir fielmente la estructura.
      const dirs = collected.dirs || [];
      const emptyDirs = dirs.filter((d) => !files.some((f) => f.relativePath.startsWith(d + '/')))
        .filter((d) => !dirs.some((o) => o !== d && o.startsWith(d + '/')));
      for (const dir of emptyDirs) {
        try {
          await post(`/api/folders/${folderUuid}/folders`, { path: dir });
        } catch (e) {
          toast('error', `No se pudo crear la carpeta "${dir}": ${e.message}`);
        }
      }
      if (emptyDirs.length && !files.length) {
        window.dispatchEvent(new CustomEvent('uploads:folder-changed', { detail: { folderUuid } }));
        toast('success', emptyDirs.length === 1 ? 'Carpeta creada.' : `${emptyDirs.length} carpetas creadas.`);
        return;
      }
      if (!files.length) {
        toast('info', 'No hay archivos para subir.');
        return;
      }

      for (const { file, relativePath } of files) {
        const item = this.makeItem({ name: file.name, size: file.size, folderUuid, folderName, relativePath, lastModified: file.lastModified });
        const ext = extension(file.name);
        if (ext && blocked.has(ext)) {
          item.status = 'error';
          item.errorRetryable = false;
          item.error = `No se permiten archivos .${ext} por seguridad. Comprímelo en un .zip si necesitas compartirlo.`;
        } else if (limits.maxFileBytes && file.size > limits.maxFileBytes) {
          item.status = 'error';
          item.errorRetryable = false;
          item.error = `Supera el límite de ${bytes(limits.maxFileBytes)} por archivo. Puedes comprimirlo o dividirlo.`;
        }
        // ¿Hay una subida interrumpida del mismo archivo? Continuarla.
        const pending = resumeStore.find(file, folderUuid);
        if (pending) {
          item.resumeSession = pending.sessionUuid;
          this.items = this.items.filter((i) => !(i.status === 'interrupted' && i.resumeSession === pending.sessionUuid));
        }
        runtime.set(item.id, { file, uploader: null });
        this.items.push(item);
      }
      this.open = true;
      this.minimized = false;
      this.announce(`${plural(files.length, 'archivo agregado', 'archivos agregados')} a la cola de subida.`);
      this.ensureTicker();
      this.pump();
    },

    // ---------------------------------------------------------------- Motor

    pump() {
      const running = this.active.length;
      let slots = QUEUE_SETTINGS.concurrency - running;
      if (slots <= 0) return;
      for (const item of this.items) {
        if (slots <= 0) break;
        if (item.status === 'queued') {
          slots--;
          this.run(item);
        }
      }
      if (!this.hasActive) this.onIdle();
    },

    async run(item) {
      const rt = runtime.get(item.id);
      if (!rt) return;
      item.status = 'preparing';
      item.error = '';
      if (!rt.uploader) {
        rt.uploader = new S3Uploader(rt.file, {
          folderUuid: item.folderUuid,
          relativePath: item.relativePath,
          onConflict: item.onConflict,
          resumeSession: item.resumeSession,
        }, {
          onProgress: (loaded) => { item.loaded = loaded; },
          onPhase: (phase) => {
            if (['preparing', 'uploading', 'retrying', 'finishing'].includes(phase) && ['preparing', 'uploading', 'retrying', 'finishing'].includes(item.status)) {
              item.status = phase;
            }
          },
        });
      }
      item.startedAt = Date.now();
      item.lastSample = { t: Date.now(), loaded: item.loaded };
      try {
        const result = await rt.uploader.start();
        if (result && result.skipped) {
          item.status = 'skipped';
          item.loaded = item.size;
        } else {
          item.status = 'done';
          item.loaded = item.size;
          item.resultName = result ? result.name : item.name;
          this.batchCounts[item.folderUuid] = (this.batchCounts[item.folderUuid] || 0) + 1;
          window.dispatchEvent(new CustomEvent('uploads:folder-changed', {
            detail: { folderUuid: item.folderUuid, targetUuid: result ? result.folderUuid : null },
          }));
        }
        item.speed = 0;
        runtime.set(item.id, { file: null, uploader: null });
      } catch (error) {
        item.speed = 0;
        if (error instanceof PausedError) {
          item.status = 'paused';
        } else if (error instanceof CancelledError) {
          item.status = 'canceled';
        } else if (error instanceof ApiError && error.code === 'name_conflict') {
          rt.uploader = null; // se reiniciará con la decisión del usuario
          item.loaded = 0;
          if (this.conflictDefault) {
            item.onConflict = this.conflictDefault;
            item.status = 'queued';
          } else {
            item.status = 'conflict';
            item.conflictInfo = error.details;
            this.showNextConflict();
          }
        } else {
          item.status = 'error';
          item.error = error instanceof ApiError || error instanceof UploadError
            ? error.message
            : `No se pudo subir "${item.name}". Inténtalo de nuevo.`;
          item.errorRetryable = !(error instanceof UploadError && !error.retryable)
            && !(error instanceof ApiError && ['blocked_extension', 'file_too_large', 'invalid_name', 'forbidden'].includes(error.code));
          if (error instanceof ApiError && error.status === 403) item.errorRetryable = false;
          if (!(error instanceof ApiError || error instanceof UploadError)) console.error(error);
        }
      } finally {
        setTimeout(() => this.pump(), 0);
      }
    },

    ensureTicker() {
      if (this.tickTimer) return;
      this.tickTimer = setInterval(() => this.tick(), 1000);
    },

    // Muestreo de velocidad (media móvil exponencial) y anuncio accesible.
    tick() {
      const now = Date.now();
      for (const item of this.active) {
        const dt = (now - item.lastSample.t) / 1000;
        if (dt <= 0) continue;
        const instant = Math.max(0, (item.loaded - item.lastSample.loaded) / dt);
        item.speed = item.speed ? item.speed * 0.7 + instant * 0.3 : instant;
        item.eta = item.speed > 0 ? (item.size - item.loaded) / item.speed : 0;
        item.lastSample = { t: now, loaded: item.loaded };
      }
      if (this.hasActive) {
        this.statusText = `${this.title}. ${this.percent}% completado.`;
      } else if (this.tickTimer) {
        clearInterval(this.tickTimer);
        this.tickTimer = null;
      }
    },

    announce(text) {
      this.statusText = text;
    },

    async onIdle() {
      const counts = this.batchCounts;
      this.batchCounts = {};
      const total = Object.values(counts).reduce((s, n) => s + n, 0);
      if (!total) return;
      const c = this.counts;
      if (c.error) {
        toast('error', `${plural(total, 'archivo subido', 'archivos subidos')}; ${plural(c.error, 'falló', 'fallaron')}. Revisa el panel de subidas.`);
      } else {
        toast('success', total === 1 ? 'Archivo subido correctamente.' : `${plural(total, 'archivo subido', 'archivos subidos')} correctamente.`);
      }
      this.announce(`Subida terminada: ${plural(total, 'archivo', 'archivos')}.`);
      for (const [folderUuid, count] of Object.entries(counts)) {
        post(`/api/folders/${folderUuid}/notify-upload`, { count }).catch(() => {});
      }
    },

    // ---------------------------------------------------------------- Acciones

    pause(item) {
      const rt = runtime.get(item.id);
      if (rt && rt.uploader && ['preparing', 'uploading', 'retrying', 'finishing'].includes(item.status)) {
        rt.uploader.pause();
      } else if (item.status === 'queued') {
        item.status = 'paused';
      }
    },

    resume(item) {
      if (item.status !== 'paused') return;
      item.status = 'queued';
      this.ensureTicker();
      this.pump();
    },

    retry(item) {
      if (item.status !== 'error') return;
      const rt = runtime.get(item.id);
      if (!rt || !rt.file) {
        item.error = 'Vuelve a seleccionar el archivo para reintentarlo.';
        return;
      }
      item.status = 'queued';
      item.error = '';
      this.ensureTicker();
      this.pump();
    },

    async cancel(item) {
      const rt = runtime.get(item.id);
      const uploader = rt && rt.uploader;
      item.status = 'canceled';
      item.speed = 0;
      if (uploader) {
        await uploader.cancel();
      } else if (item.resumeSession) {
        del(`/api/uploads/${item.resumeSession}`).catch(() => {});
        resumeStore.remove(item.resumeSession);
      }
      runtime.delete(item.id);
      this.pump();
    },
    remove(item) {
      runtime.delete(item.id);
      this.items = this.items.filter((i) => i.id !== item.id);
      if (!this.items.length) this.open = false;
    },

    pauseAll() {
      for (const item of this.items) this.pause(item);
    },
    resumeAll() {
      for (const item of this.items) if (item.status === 'paused') item.status = 'queued';
      this.ensureTicker();
      this.pump();
    },
    retryAll() {
      for (const item of this.items) if (item.status === 'error' && item.errorRetryable) this.retry(item);
    },
    async cancelAll() {
      for (const item of [...this.items]) {
        if (!['done', 'skipped', 'canceled'].includes(item.status)) await this.cancel(item);
      }
    },
    clearFinished() {
      this.items = this.items.filter((i) => !['done', 'skipped', 'canceled'].includes(i.status));
      if (!this.items.length) this.open = false;
    },
    close() {
      if (this.hasActive) {
        this.minimized = true;
        return;
      }
      this.clearFinished();
      if (this.items.length) this.minimized = true;
    },
    // Continuar una subida interrumpida: el navegador exige volver a elegir el archivo.
    pickForResume(item) {
      const input = document.createElement('input');
      input.type = 'file';
      input.onchange = () => {
        const file = input.files && input.files[0];
        if (!file) return;
        if (file.name !== item.name || file.size !== item.size) {
          toast('error', `Elige el mismo archivo: "${item.name}" (${bytes(item.size)}).`);
          return;
        }
        runtime.set(item.id, { file, uploader: null });
        item.status = 'queued';
        this.ensureTicker();
        this.pump();
      };
      input.click();
    },

    // ---------------------------------------------------------------- Conflictos de nombre

    showNextConflict() {
      if (this.conflict) return;
      const item = this.items.find((i) => i.status === 'conflict');
      if (!item) return;
      this.conflictApplyAll = false;
      this.conflict = {
        id: item.id,
        name: item.name,
        folder: (item.conflictInfo && item.conflictInfo.folder) || '',
        existing: (item.conflictInfo && item.conflictInfo.existing) || null,
        pending: this.items.filter((i) => i.status === 'conflict' || i.status === 'queued').length,
      };
    },

    resolveConflict(choice) {
      if (!this.conflict) return;
      const targets = this.conflictApplyAll
        ? this.items.filter((i) => i.status === 'conflict')
        : this.items.filter((i) => i.id === this.conflict.id);
      if (this.conflictApplyAll) this.conflictDefault = choice;
      for (const item of targets) {
        if (choice === 'skip') {
          item.status = 'skipped';
          item.loaded = item.size;
          runtime.delete(item.id);
        } else {
          item.onConflict = choice;
          item.status = 'queued';
        }
      }
      this.conflict = null;
      this.showNextConflict();
      this.ensureTicker();
      this.pump();
    },

    // ---------------------------------------------------------------- Conexión

    onOffline() {
      this.autoPaused = this.active.map((i) => i.id);
      if (this.autoPaused.length) {
        this.pauseAll();
        toast('error', 'Sin conexión a internet. Las subidas se reanudarán automáticamente al volver la conexión.');
      }
    },
    onOnline() {
      if (!this.autoPaused.length) return;
      for (const item of this.items) {
        if (this.autoPaused.includes(item.id) && item.status === 'paused') item.status = 'queued';
      }
      this.autoPaused = [];
      toast('success', 'Conexión recuperada. Reanudando subidas…');
      this.ensureTicker();
      this.pump();
    },
  };
}
