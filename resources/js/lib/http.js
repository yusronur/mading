// Permintaan JSON ke server Laravel (untuk aksi di luar navigasi Inertia).
function xsrf() {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

const headers = () => ({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() });

async function handle(res) {
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const msg = data.message || Object.values(data.errors || {})[0]?.[0] || 'Terjadi kesalahan. Coba lagi.';
        throw Object.assign(new Error(msg), { status: res.status, data });
    }
    return data;
}

export async function getJson(url) {
    return handle(await fetch(url, { headers: headers(), credentials: 'same-origin' }));
}

export async function postJson(url, body = {}, method = 'POST') {
    return handle(await fetch(url, {
        method,
        headers: { ...headers(), 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    }));
}

export const deleteJson = (url) => postJson(url, {}, 'DELETE');
export const putJson = (url, body = {}) => postJson(url, body, 'PUT');

/** Unggah satu berkas dengan progres (0-100). */
export function upload(url, file, onProgress = () => {}, field = 'file') {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        Object.entries(headers()).forEach(([k, v]) => xhr.setRequestHeader(k, v));
        xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(Math.round((e.loaded / e.total) * 100));
        xhr.onload = () => {
            let data = {};
            try { data = JSON.parse(xhr.responseText); } catch (e) { /* abaikan */ }
            if (xhr.status >= 200 && xhr.status < 300) resolve(data);
            else if (xhr.status === 413) reject(new Error('Berkas terlalu besar untuk server.'));
            else reject(new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'Unggahan gagal.'));
        };
        xhr.onerror = () => reject(new Error('Koneksi terputus saat mengunggah.'));
        const fd = new FormData();
        fd.append(field, file);
        xhr.send(fd);
    });
}

export function formatBytes(n) {
    if (n >= 1048576) return `${(n / 1048576).toFixed(1).replace('.', ',')} MB`;
    return `${Math.max(1, Math.round(n / 1024))} KB`;
}
