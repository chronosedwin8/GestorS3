// Modal "Compartir carpeta": miembros, invitaciones por correo y enlaces públicos.

import { get, post, patch, del, copyText, appConfig, iconsUrl } from './api.js';
import { dateTime, relative } from './format.js';

function toast(type, message) {
  window.Alpine.store('toasts').push(type, message);
}

export const PERMISSION_LABELS = { owner: 'Propietario', editor: 'Editor', viewer: 'Lector' };

export function shareDialog() {
  return {
    open: false,
    tab: 'people',
    icons: iconsUrl,
    folder: null, // { uuid, name, rootName, isRoot, caps }
    loading: false,
    error: '',
    data: null, // respuesta de /members
    invite: { emails: '', permission: 'viewer', busy: false, results: [] },
    links: [],
    linksLoading: false,
    newLink: { password: '', expiresInDays: '0', maxDownloads: '', busy: false, show: false },
    removing: null,
    me: appConfig.user ? appConfig.user.uuid : null,
    labels: PERMISSION_LABELS,
    dateTime, relative,

    init() {
      window.addEventListener('share:open', (e) => this.show(e.detail));
    },

    async show(folder) {
      this.folder = folder;
      this.open = true;
      this.tab = 'people';
      this.error = '';
      this.invite = { emails: '', permission: 'viewer', busy: false, results: [] };
      this.newLink = { password: '', expiresInDays: '0', maxDownloads: '', busy: false, show: false };
      this.links = [];
      await this.loadMembers();
      this.$nextTick(() => this.$refs.inviteEmails && this.$refs.inviteEmails.focus());
    },

    close() {
      this.open = false;
    },

    async loadMembers() {
      this.loading = true;
      try {
        this.data = await get(`/api/folders/${this.folder.uuid}/members`);
        if (!this.data.grantable.includes(this.invite.permission)) {
          this.invite.permission = this.data.grantable[this.data.grantable.length - 1] || 'viewer';
        }
      } catch (error) {
        this.error = error.message;
      } finally {
        this.loading = false;
      }
    },

    get canInvite() {
      return this.data && this.data.grantable.length > 0;
    },

    get emailCount() {
      return this.invite.emails.split(/[\s,;]+/).filter((e) => e.includes('@')).length;
    },

    async sendInvites() {
      if (!this.emailCount) {
        toast('error', 'Escribe al menos un correo electrónico.');
        return;
      }
      this.invite.busy = true;
      try {
        const res = await post(`/api/folders/${this.folder.uuid}/members`, { emails: this.invite.emails, permission: this.invite.permission });
        this.invite.results = res.results;
        const ok = res.results.filter((r) => r.status === 'added' || r.status === 'invited').length;
        if (ok) toast('success', ok === 1 ? 'Acceso compartido.' : `Acceso compartido con ${ok} personas.`);
        this.invite.emails = res.results.filter((r) => r.status === 'error').map((r) => r.email).join(', ');
        await this.loadMembers();
      } catch (error) {
        toast('error', error.message);
      } finally {
        this.invite.busy = false;
      }
    },

    async changePermission(member, permission) {
      try {
        await patch(`/api/folders/${this.folder.uuid}/members/${member.userUuid}`, { permission });
        member.permission = permission;
        toast('success', `${member.name} ahora es ${PERMISSION_LABELS[permission].toLowerCase()}.`);
      } catch (error) {
        toast('error', error.message);
        await this.loadMembers();
      }
    },

    async removeMember(member) {
      const self = member.userUuid === this.me;
      try {
        await del(`/api/folders/${this.folder.uuid}/members/${member.userUuid}`);
        this.removing = null;
        if (self) {
          toast('success', `Saliste de “${this.data.root.name}”.`);
          this.close();
          window.dispatchEvent(new CustomEvent('explorer:left-folder'));
          return;
        }
        toast('success', `${member.name} ya no tiene acceso.`);
        await this.loadMembers();
      } catch (error) {
        toast('error', error.message);
      }
    },

    async cancelInvitation(inv) {
      try {
        await del(`/api/invitations/${inv.uuid}`);
        toast('success', `Invitación a ${inv.email} cancelada.`);
        await this.loadMembers();
      } catch (error) {
        toast('error', error.message);
      }
    },

    // ---------------------------------------------------------------- Enlaces públicos

    async openLinks() {
      this.tab = 'link';
      this.linksLoading = true;
      try {
        this.links = (await get(`/api/folders/${this.folder.uuid}/share-links`)).links;
      } catch (error) {
        toast('error', error.message);
      } finally {
        this.linksLoading = false;
      }
    },

    async createLink() {
      this.newLink.busy = true;
      try {
        const link = await post(`/api/folders/${this.folder.uuid}/share-links`, {
          password: this.newLink.password,
          expiresInDays: parseInt(this.newLink.expiresInDays, 10) || 0,
          maxDownloads: parseInt(this.newLink.maxDownloads, 10) || 0,
        });
        this.links.unshift(link);
        this.newLink = { password: '', expiresInDays: '0', maxDownloads: '', busy: false, show: false };
        await this.copy(link.url, 'Enlace creado y copiado. Pégalo donde quieras compartirlo.');
      } catch (error) {
        toast('error', error.message);
      } finally {
        this.newLink.busy = false;
      }
    },

    async revokeLink(link) {
      try {
        await del(`/api/share-links/${link.uuid}`);
        link.revoked = true;
        link.active = false;
        link.status = 'revoked';
        toast('success', 'Enlace desactivado. Ya nadie podrá usarlo.');
      } catch (error) {
        toast('error', error.message);
      }
    },

    async copy(text, message = 'Copiado al portapapeles.') {
      if (await copyText(text)) toast('success', message);
      else toast('error', 'No se pudo copiar. Selecciona el texto y cópialo manualmente.');
    },

    linkStatus(link) {
      return { active: 'Activo', revoked: 'Desactivado', expired: 'Expirado', exhausted: 'Sin descargas disponibles' }[link.status] || link.status;
    },
  };
}
