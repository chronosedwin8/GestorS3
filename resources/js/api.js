// Cliente de la API JSON: CSRF, errores con forma consistente y mensajes en español.

const configEl = document.getElementById('app-config');
export const appConfig = configEl ? JSON.parse(configEl.textContent || '{}') : {};

export const iconsUrl = `${appConfig.basePath || ''}/assets/icons.svg`;

export function url(path) {
  return `${appConfig.basePath || ''}${path}`;
}

export class ApiError extends Error {
  constructor(message, code = 'error', status = 0, details = {}) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.details = details || {};
  }

  get retryable() {
    return this.status === 0 || this.status === 429 || this.status >= 500;
  }
}

let sessionWarningShown = false;

function onSessionExpired() {
  if (sessionWarningShown) return;
  sessionWarningShown = true;
  window.dispatchEvent(new CustomEvent('app:session-expired'));
}

export async function api(method, path, body, { signal } = {}) {
  const headers = { Accept: 'application/json', 'X-CSRF-Token': appConfig.csrf || '' };
  const init = { method, headers, credentials: 'same-origin', signal };
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }
  let response;
  try {
    response = await fetch(url(path), init);
  } catch (error) {
    if (error.name === 'AbortError') throw error;
    throw new ApiError('No hay conexión con el servidor. Revisa tu conexión a internet e inténtalo de nuevo.', 'network_error', 0);
  }
  let json = null;
  try {
    json = await response.json();
  } catch {
    json = null;
  }
  if (!response.ok || !json || json.ok === false) {
    const err = (json && json.error) || {};
    if (response.status === 401) onSessionExpired();
    const fallback = response.status >= 500
      ? 'El servidor tuvo un problema. Inténtalo de nuevo en unos segundos.'
      : `No se pudo completar la acción (error ${response.status}).`;
    throw new ApiError(err.message || fallback, err.code || `http_${response.status}`, response.status, err.details);
  }
  return json.data;
}

export const get = (path, opts) => api('GET', path, undefined, opts);
export const post = (path, body = {}, opts) => api('POST', path, body, opts);
export const patch = (path, body = {}, opts) => api('PATCH', path, body, opts);
export const del = (path, opts) => api('DELETE', path, undefined, opts);

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try {
      ok = document.execCommand('copy');
    } catch {
      ok = false;
    }
    ta.remove();
    return ok;
  }
}
