// Formato legible en español: tamaños, velocidades, duraciones y fechas.

const numberFmt = new Intl.NumberFormat('es', { maximumFractionDigits: 1 });

export function bytes(n) {
  n = Number(n) || 0;
  if (n < 1024) return `${n} B`;
  const units = ['KB', 'MB', 'GB', 'TB'];
  let value = n / 1024;
  let i = 0;
  while (value >= 1024 && i < units.length - 1) {
    value /= 1024;
    i++;
  }
  return `${numberFmt.format(value)} ${units[i]}`;
}

export function speed(bytesPerSecond) {
  if (!bytesPerSecond || bytesPerSecond < 1) return '';
  return `${bytes(bytesPerSecond)}/s`;
}

export function duration(seconds) {
  if (!isFinite(seconds) || seconds <= 0) return '';
  seconds = Math.round(seconds);
  if (seconds < 60) return `${seconds} s`;
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `${minutes} min`;
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m ? `${h} h ${m} min` : `${h} h`;
}

const dateFmt = new Intl.DateTimeFormat('es', { day: 'numeric', month: 'short', year: 'numeric' });
const dateTimeFmt = new Intl.DateTimeFormat('es', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const rtf = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });

export function date(iso) {
  if (!iso) return '—';
  return dateFmt.format(new Date(iso));
}

export function dateTime(iso) {
  if (!iso) return '—';
  return dateTimeFmt.format(new Date(iso));
}

export function relative(iso) {
  if (!iso) return '—';
  const diff = (new Date(iso).getTime() - Date.now()) / 1000;
  const abs = Math.abs(diff);
  if (abs < 45) return 'hace un momento';
  if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute');
  if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour');
  if (abs < 86400 * 7) return rtf.format(Math.round(diff / 86400), 'day');
  return date(iso);
}

export function plural(n, one, many) {
  return `${new Intl.NumberFormat('es').format(n)} ${n === 1 ? one : many}`;
}

// Icono y color por tipo de archivo (coincide con App\Support\Present::kind).
const KIND_ICONS = {
  image: 'file-image', video: 'file-video', audio: 'file-audio', pdf: 'file-pdf', doc: 'file-doc',
  sheet: 'file-sheet', slides: 'file-slides', archive: 'file-archive', code: 'file-code', text: 'file-text', file: 'file',
};
const KIND_COLORS = {
  image: 'text-fuchsia-500', video: 'text-rose-500', audio: 'text-violet-500', pdf: 'text-red-500', doc: 'text-blue-600',
  sheet: 'text-emerald-600', slides: 'text-orange-500', archive: 'text-amber-600', code: 'text-slate-500', text: 'text-slate-500', file: 'text-slate-400',
};

export function kindIcon(kind) {
  return KIND_ICONS[kind] || 'file';
}

export function kindColor(kind) {
  return KIND_COLORS[kind] || 'text-slate-400';
}

const EXT_KINDS = {
  image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tif', 'tiff', 'heic', 'avif', 'ico', 'psd', 'ai', 'eps'],
  video: ['mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', 'flv', 'm4v', 'mpg', 'mpeg', 'ogv', '3gp'],
  audio: ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'wma', 'opus', 'mid', 'midi'],
  pdf: ['pdf'],
  doc: ['doc', 'docx', 'odt', 'rtf', 'pages'],
  sheet: ['xls', 'xlsx', 'ods', 'csv', 'tsv', 'numbers', 'xlsm'],
  slides: ['ppt', 'pptx', 'odp', 'key'],
  archive: ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'iso', 'dmg'],
  code: ['html', 'htm', 'css', 'js', 'ts', 'json', 'xml', 'php', 'py', 'java', 'c', 'cpp', 'h', 'cs', 'go', 'rb', 'sql', 'sh', 'yml', 'yaml', 'bat', 'ps1'],
  text: ['txt', 'md', 'log', 'ini', 'conf', 'env'],
};

export function extension(name) {
  const i = name.lastIndexOf('.');
  return i > 0 ? name.slice(i + 1).toLowerCase() : '';
}

export function kindOf(name) {
  const ext = extension(name);
  for (const [kind, list] of Object.entries(EXT_KINDS)) {
    if (list.includes(ext)) return kind;
  }
  return 'file';
}
