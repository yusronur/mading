import { reactive } from 'vue';

// Dialog konfirmasi berbasis CSS (komponen ConfirmDialog), pengganti dialog bawaan peramban.
// Pemakaian:  if (await askConfirm({ title: 'Hapus?', message: '...', tone: 'danger' })) { ... }
export const confirmState = reactive({ open: false, title: '', message: '', confirmText: 'Ya', cancelText: 'Batal', tone: 'primary' });
let resolver = null;

export function askConfirm(options = {}) {
    if (resolver) resolver(false);

    Object.assign(confirmState, {
        title: 'Yakin?', message: '', confirmText: 'Ya', cancelText: 'Batal', tone: 'primary', ...options, open: true,
    });

    return new Promise((resolve) => {
        resolver = resolve;
    });
}

export function answerConfirm(value) {
    confirmState.open = false;
    const done = resolver;
    resolver = null;
    done?.(value);
}
