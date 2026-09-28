// Subida directa navegador -> S3 con URLs prefirmadas.
//  - PUT simple para archivos pequeños.
//  - Multipart para archivos grandes: partes en paralelo, reintentos con backoff,
//    pausa/reanudación y recuperación tras recargar la página (ListParts en el servidor).

import { post, get, del, ApiError, sleep, appConfig } from './api.js';

export const UPLOAD_SETTINGS = {
  partConcurrency: 4, // partes simultáneas dentro de un multipart
  signBatch: 20, // URLs prefirmadas por solicitud
  maxRetries: 5, // reintentos por parte / archivo
};

export class UploadError extends Error {
  constructor(message, { code = 'upload_error', retryable = true, details = {} } = {}) {
    super(message);
    this.name = 'UploadError';
    this.code = code;
    this.retryable = retryable;
    this.details = details;
  }
}

export class PausedError extends Error {
  constructor() {
    super('paused');
    this.name = 'PausedError';
  }
}

export class CancelledError extends Error {
  constructor() {
    super('cancelled');
    this.name = 'CancelledError';
  }
}

class HttpError extends Error {
  constructor(status, body) {
    super(`HTTP ${status}`);
    this.status = status;
    this.body = body || '';
  }

  get expired() {
    return this.status === 403 && /expired|Request has expired/i.test(this.body);
  }

  get s3Message() {
    const m = /<Message>([^<]+)<\/Message>/.exec(this.body);
    return m ? m[1] : '';
  }
}

class NetworkError extends Error {}
class AbortedError extends Error {}

function putWithProgress(url, body, headers, onProgress, registry) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    registry.add(xhr);
    xhr.open('PUT', url, true);
    for (const [name, value] of Object.entries(headers || {})) {
      xhr.setRequestHeader(name, value);
    }
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) onProgress(e.loaded);
    };
    xhr.onload = () => {
      registry.delete(xhr);
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve({ etag: xhr.getResponseHeader('ETag') });
      } else {
        reject(new HttpError(xhr.status, xhr.responseText));
      }
    };
    xhr.onerror = () => {
      registry.delete(xhr);
      reject(new NetworkError('network'));
    };
    xhr.ontimeout = xhr.onerror;
    xhr.onabort = () => {
      registry.delete(xhr);
      reject(new AbortedError('aborted'));
    };
    xhr.send(body);
  });
}

function backoff(attempt) {
  return Math.min(30000, 1000 * 2 ** attempt) + Math.random() * 500;
}

function storageNetworkMessage(fileName) {
  let msg = `Se perdió la conexión mientras se subía "${fileName}". Revisa tu internet y pulsa Reintentar.`;
  if (appConfig.user && appConfig.user.isAdmin) {
    msg += ` (Administrador: si ocurre con todos los archivos, verifica que la configuración CORS del bucket permita el origen ${appConfig.appOrigin} con PUT y exponga la cabecera ETag.)`;
  }
  return msg;
}

// ------------------------------------------------------------------ Persistencia para reanudar tras recargar

const STORE_KEY = 'fs.uploads.v1';

export const resumeStore = {
  all() {
    try {
      const list = JSON.parse(localStorage.getItem(STORE_KEY) || '[]');
      const me = appConfig.user ? appConfig.user.uuid : null;
      return list.filter((e) => e.userUuid === me && Date.now() - e.createdAt < 23 * 3600 * 1000);
    } catch {
      return [];
    }
  },
  save(entry) {
    const list = this.all().filter((e) => e.sessionUuid !== entry.sessionUuid);
    list.push(entry);
    try {
      localStorage.setItem(STORE_KEY, JSON.stringify(list));
    } catch {
      /* almacenamiento lleno o bloqueado */
    }
  },
  remove(sessionUuid) {
    try {
      localStorage.setItem(STORE_KEY, JSON.stringify(this.all().filter((e) => e.sessionUuid !== sessionUuid)));
    } catch {
      /* ignorar */
    }
  },
  find(file, folderUuid) {
    return this.all().find((e) => e.name === file.name && e.size === file.size && e.lastModified === file.lastModified && e.folderUuid === folderUuid);
  },
};

// ------------------------------------------------------------------ Uploader

export class S3Uploader {
  /**
   * @param {File} file
   * @param {{folderUuid: string, relativePath?: string, onConflict?: string|null, resumeSession?: string|null}} target
   * @param {{onProgress?: (loaded:number)=>void, onPhase?: (phase:string)=>void}} callbacks
   */
  constructor(file, target, callbacks = {}) {
    this.file = file;
    this.target = target;
    this.onProgress = callbacks.onProgress || (() => {});
    this.onPhase = callbacks.onPhase || (() => {});
    this.xhrs = new Set();
    this.mode = null;
    this.fileUuid = null;
    this.sessionUuid = null;
    this.single = null;
    this.partSize = 0;
    this.totalParts = 0;
    this.completed = new Map(); // parte -> etag
    this.partLoaded = new Map(); // parte -> bytes subidos
    this.urlCache = new Map(); // parte -> { url, expires }
    this.signing = null;
    this.stopReason = null; // 'pause' | 'cancel' | Error
    this.result = null;
    this.finalName = file.name;
    this.finalFolderUuid = target.folderUuid;
  }

  get loaded() {
    if (this.mode === 'single') return this.singleLoaded || 0;
    let sum = 0;
    for (const v of this.partLoaded.values()) sum += v;
    return Math.min(sum, this.file.size);
  }

  report() {
    this.onProgress(this.loaded);
  }

  check() {
    if (this.stopReason === 'cancel') throw new CancelledError();
    if (this.stopReason === 'pause') throw new PausedError();
    if (this.stopReason instanceof Error) throw this.stopReason;
  }

  abortRequests() {
    for (const xhr of [...this.xhrs]) xhr.abort();
    this.xhrs.clear();
  }

  pause() {
    this.stopReason = 'pause';
    this.abortRequests();
  }

  async cancel() {
    this.stopReason = 'cancel';
    this.abortRequests();
    try {
      if (this.sessionUuid) {
        await del(`/api/uploads/${this.sessionUuid}`);
        resumeStore.remove(this.sessionUuid);
      } else if (this.fileUuid) {
        await post(`/api/files/${this.fileUuid}/abort`);
      }
    } catch {
      /* la limpieza programada se encargará */
    }
  }

  /**
   * Ejecuta (o continúa) la subida. Resuelve con el archivo creado o {skipped:true}.
   * Rechaza con PausedError / CancelledError / UploadError / ApiError.
   */
  async start() {
    this.stopReason = null;
    if (!this.mode) {
      if (this.target.resumeSession) {
        await this.restore(this.target.resumeSession);
      }
      if (!this.mode) await this.init();
    }
    if (this.mode === 'skipped') return { skipped: true };
    this.check();
    const result = this.mode === 'single' ? await this.runSingle() : await this.runMultipart();
    this.result = result;
    return result;
  }

  async init() {
    this.onPhase('preparing');
    const data = await post(`/api/folders/${this.target.folderUuid}/files/init`, {
      name: this.file.name,
      size: this.file.size,
      mime: this.file.type || '',
      relativePath: this.target.relativePath || '',
      onConflict: this.target.onConflict || null,
    });
    this.mode = data.mode;
    if (data.mode === 'skipped') return;
    this.fileUuid = data.fileUuid;
    this.finalName = data.name;
    this.finalFolderUuid = data.folderUuid;
    if (data.mode === 'single') {
      this.single = { url: data.url, headers: data.headers || {} };
    } else {
      this.sessionUuid = data.uploadSessionUuid;
      this.partSize = data.partSize;
      this.totalParts = data.totalParts;
      resumeStore.save({
        sessionUuid: this.sessionUuid,
        fileUuid: this.fileUuid,
        name: this.file.name,
        size: this.file.size,
        lastModified: this.file.lastModified,
        folderUuid: this.target.folderUuid,
        relativePath: this.target.relativePath || '',
        userUuid: appConfig.user ? appConfig.user.uuid : null,
        createdAt: Date.now(),
      });
    }
  }

  // Reanudar un multipart iniciado antes de recargar la página.
  async restore(sessionUuid) {
    this.onPhase('preparing');
    let status;
    try {
      status = await get(`/api/uploads/${sessionUuid}`);
    } catch {
      resumeStore.remove(sessionUuid);
      return;
    }
    if (status.status !== 'active' || status.fileStatus !== 'uploading' || status.size !== this.file.size) {
      resumeStore.remove(sessionUuid);
      return;
    }
    this.mode = 'multipart';
    this.sessionUuid = sessionUuid;
    this.fileUuid = status.fileUuid;
    this.finalName = status.name;
    this.partSize = status.partSize;
    this.totalParts = status.totalParts;
    for (const p of status.parts || []) {
      this.completed.set(p.partNumber, p.etag);
      this.partLoaded.set(p.partNumber, p.size);
    }
    this.report();
  }

  // ---------------------------------------------------------------- PUT simple

  async runSingle() {
    this.onPhase('uploading');
    let attempt = 0;
    for (;;) {
      this.check();
      try {
        await putWithProgress(this.single.url, this.file, this.single.headers, (loaded) => {
          this.singleLoaded = loaded;
          this.report();
        }, this.xhrs);
        break;
      } catch (error) {
        this.singleLoaded = 0;
        this.report();
        if (error instanceof AbortedError) this.check();
        if (error instanceof HttpError && error.expired) {
          this.single = await post(`/api/files/${this.fileUuid}/sign`);
          continue;
        }
        if (error instanceof HttpError && error.status >= 400 && error.status < 500 && error.status !== 408 && error.status !== 429) {
          throw new UploadError(`El almacenamiento rechazó "${this.file.name}": ${error.s3Message || 'error ' + error.status}.`, { code: 's3_rejected', retryable: true });
        }
        if (attempt >= UPLOAD_SETTINGS.maxRetries) {
          throw new UploadError(storageNetworkMessage(this.file.name), { code: 'network' });
        }
        this.onPhase('retrying');
        await sleep(backoff(attempt++));
        this.onPhase('uploading');
      }
    }
    this.singleLoaded = this.file.size;
    this.report();
    this.onPhase('finishing');
    return this.withRetry(() => post(`/api/files/${this.fileUuid}/confirm`));
  }

  // ---------------------------------------------------------------- Multipart

  pendingParts() {
    const out = [];
    for (let n = 1; n <= this.totalParts; n++) {
      if (!this.completed.has(n)) out.push(n);
    }
    return out;
  }

  async runMultipart() {
    this.onPhase('uploading');
    const queue = this.pendingParts();
    const worker = async () => {
      while (queue.length) {
        this.check();
        const n = queue.shift();
        await this.uploadPart(n);
      }
    };
    const workers = [];
    for (let i = 0; i < Math.min(UPLOAD_SETTINGS.partConcurrency, queue.length); i++) {
      workers.push(worker().catch((error) => {
        // Detener al resto de trabajadores con el mismo motivo.
        if (!this.stopReason) {
          this.stopReason = error instanceof PausedError ? 'pause' : error instanceof CancelledError ? 'cancel' : error;
          this.abortRequests();
        }
        throw error;
      }));
    }
    const results = await Promise.allSettled(workers);
    const failure = results.find((r) => r.status === 'rejected');
    if (failure) {
      this.check();
      throw failure.reason;
    }
    this.check();
    this.onPhase('finishing');
    const parts = [...this.completed.entries()].sort((a, b) => a[0] - b[0]).map(([partNumber, etag]) => ({ partNumber, etag }));
    const file = await this.withRetry(() => post(`/api/uploads/${this.sessionUuid}/complete`, { parts }));
    resumeStore.remove(this.sessionUuid);
    return file;
  }

  async uploadPart(n) {
    const start = (n - 1) * this.partSize;
    const end = Math.min(start + this.partSize, this.file.size);
    const blob = this.file.slice(start, end);
    let attempt = 0;
    for (;;) {
      this.check();
      const url = await this.partUrl(n);
      try {
        const { etag } = await putWithProgress(url, blob, {}, (loaded) => {
          this.partLoaded.set(n, loaded);
          this.report();
        }, this.xhrs);
        if (!etag) {
          throw new UploadError(
            'El almacenamiento no devolvió la confirmación de cada parte (cabecera ETag). Un administrador debe agregar "ETag" en ExposeHeaders de la configuración CORS del bucket.',
            { code: 'cors_etag', retryable: false },
          );
        }
        this.completed.set(n, etag);
        this.partLoaded.set(n, end - start);
        this.report();
        return;
      } catch (error) {
        if (error instanceof UploadError) throw error;
        this.partLoaded.set(n, 0);
        this.report();
        if (error instanceof AbortedError) {
          this.check();
        }
        if (error instanceof HttpError && error.expired) {
          this.urlCache.delete(n);
          continue;
        }
        if (error instanceof HttpError && error.status === 404) {
          throw new UploadError('La subida expiró en el almacenamiento. Vuelve a subir el archivo.', { code: 'no_such_upload', retryable: false });
        }
        if (attempt >= UPLOAD_SETTINGS.maxRetries) {
          throw new UploadError(storageNetworkMessage(this.file.name), { code: 'network' });
        }
        this.onPhase('retrying');
        await sleep(backoff(attempt++));
        this.onPhase('uploading');
      }
    }
  }

  async partUrl(n) {
    const cached = this.urlCache.get(n);
    if (cached && cached.expires > Date.now() + 60000) return cached.url;
    if (this.signing) {
      await this.signing.catch(() => {});
      const again = this.urlCache.get(n);
      if (again && again.expires > Date.now() + 60000) return again.url;
    }
    const wanted = [n];
    for (const p of this.pendingParts()) {
      if (wanted.length >= UPLOAD_SETTINGS.signBatch) break;
      if (p !== n && !this.urlCache.has(p)) wanted.push(p);
    }
    this.signing = this.withRetry(() => post(`/api/uploads/${this.sessionUuid}/parts/sign`, { partNumbers: wanted }))
      .then((data) => {
        const expires = Date.now() + (data.expiresIn || 1800) * 1000;
        for (const part of data.parts) this.urlCache.set(part.partNumber, { url: part.url, expires });
      })
      .finally(() => {
        this.signing = null;
      });
    await this.signing;
    const entry = this.urlCache.get(n);
    if (!entry) throw new UploadError('No se pudo preparar la subida de una parte.', { code: 'sign_failed' });
    return entry.url;
  }

  // Reintenta llamadas a nuestra API ante fallos de red / 5xx / 429.
  async withRetry(fn) {
    let attempt = 0;
    for (;;) {
      this.check();
      try {
        return await fn();
      } catch (error) {
        if (!(error instanceof ApiError) || !error.retryable || attempt >= UPLOAD_SETTINGS.maxRetries) throw error;
        const wait = error.status === 429 && error.details.retryAfter ? error.details.retryAfter * 1000 : backoff(attempt);
        attempt++;
        await sleep(Math.min(wait, 60000));
      }
    }
  }
}
