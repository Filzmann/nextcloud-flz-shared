import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const browserRoot = process.argv[2];
if (!browserRoot) {
    throw new Error('Das Browser-Bundle-Verzeichnis fehlt.');
}

const requests = [];
const noticeElement = { className: '', hidden: true, textContent: '' };
const window = {
    fetch: async (url, options) => {
        requests.push({ url, options });
        return {
            ok: true,
            status: 200,
            text: async () => '{"ok":true}',
        };
    },
};
window.window = window;
window.OC = {
    generateUrl: path => `/index.php${path}`,
    requestToken: 'synthetic-token',
};

const context = vm.createContext({
    document: {
        getElementById: id => id === 'notice' ? noticeElement : null,
    },
    encodeURIComponent,
    Error,
    JSON,
    OC: window.OC,
    String,
    window,
});

for (const file of ['api-client.js', 'model.js', 'repository.js', 'ui.js']) {
    const source = readFileSync(resolve(browserRoot, file), 'utf8');
    vm.runInContext(source, context, { filename: file });
}

const shared = window.OCA?.FlzUrlaub?.Shared;
if (!shared?.api?.ApiClient || !shared?.models?.Model || !shared?.repositories?.Repository || !shared?.ui?.Notice) {
    throw new Error('Das isolierte Browser-Bundle stellt nicht alle Kategorie-A-Primitives bereit.');
}
if ('LocalBase' in window) {
    throw new Error('Das Browser-Bundle erzeugt weiterhin ein window.LocalBase-Global.');
}

const client = new shared.api.ApiClient({ appId: 'flzurlaub' });
const response = await client.request('/api/ping', { method: 'POST', body: '{}' });
if (response.ok !== true || requests.length !== 1) {
    throw new Error('Der gebündelte ApiClient erhält keine erfolgreiche Antwort.');
}
if (requests[0].url !== '/index.php/apps/flzurlaub/api/ping'
    || requests[0].options.headers.requesttoken !== 'synthetic-token') {
    throw new Error('Der gebündelte ApiClient bewahrt URL- und CSRF-Vertrag nicht.');
}

const notice = new shared.ui.Notice('notice', {
    baseClass: 'notice',
    typeClassPrefix: 'notice--',
});
notice.success('Bereit');
if (noticeElement.textContent !== 'Bereit' || noticeElement.hidden || noticeElement.className !== 'notice notice--success') {
    throw new Error('Die gebündelte Notice bewahrt den sichtbaren Erfolgszustand nicht.');
}

console.log('isolated browser runtime contract passed');
